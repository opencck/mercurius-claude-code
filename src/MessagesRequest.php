<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use JsonException;
use stdClass;

/**
 * A request of the Messages API as Claude Code can carry it: the history as the frames of its input, the system text
 * as a file, and the rest — tools, the limit of the answer, thinking — as the body the CLI adds to its own request. A
 * field the service does not carry is a refusal, not a silent drop: the application must know what was sent. Cache
 * marks are taken and dropped: the CLI places its own.
 *
 * JSON objects stay objects all the way: an empty `input` or `properties` must not become a list.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L338-L449 (request_body)
 *      — an unknown field is refused; thinking that cannot be turned off becomes the lowest effort; the tools go into
 *      the body under the names of the CLI and into the list of the inert server. Here the request already has the
 *      shape of the Messages API, so there is no mapping from chat completions and no sampling fields
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L228-L283
 *      (prepare_history) — replies of the user in a row become one frame; the history ends with the user
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L194-L225
 *      (_normalize_history_tool_ids) — an id of a call from another provider is replaced in the call and its result
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L184-L192
 *      (conversation_session_id) — one session of the CLI per conversation
 */
final class MessagesRequest {
    public const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    private const FIELDS = ['model', 'system', 'messages', 'tools', 'max_tokens', 'output_config', 'thinking', 'stream', 'stop_sequences'];
    private const BLOCKS = ['text', 'image', 'document', 'tool_use', 'tool_result', 'thinking', 'redacted_thinking'];
    private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * @param string $model the model as the application named it
     * @param string $native the model as Claude Code takes it
     * @param non-empty-list<array{type: string, message: array{role: string, content: list<stdClass>}}> $frames
     * @param string $body what the CLI adds to its request, as JSON
     * @param list<array{name: string, description: string, inputSchema: stdClass}> $manifest the tools the inert server lists
     */
    private function __construct(
        public readonly string $model,
        public readonly string $native,
        public readonly string $system,
        public readonly array $frames,
        public readonly string $body,
        public readonly array $manifest,
        public readonly ToolNames $names,
        public readonly bool $stream,
        public readonly ?string $effort,
        public readonly ?int $maxTokens,
    ) {}

    /** @throws RequestRejected */
    public static function parse(string $json): self {
        try {
            /** @var mixed $data */
            $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RequestRejected('The body of the request is not JSON');
        }
        if (!$data instanceof stdClass) {
            throw new RequestRejected('The body of the request must be a JSON object');
        }
        $unknown = array_diff(array_keys(get_object_vars($data)), self::FIELDS);
        if ($unknown !== []) {
            sort($unknown);
            throw new RequestRejected('Unsupported request fields: ' . implode(', ', $unknown));
        }
        /** @var mixed $model */
        $model = $data->model ?? null;
        if (!is_string($model) || $model === '') {
            throw new RequestRejected('model is required');
        }
        // the name becomes an argument of the CLI: it must not read as an option
        if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/\[\]-]{0,127}$~', $model) !== 1) {
            throw new RequestRejected('model is not a name of a model');
        }
        /** @var mixed $stream */
        $stream = $data->stream ?? false;
        if (!is_bool($stream)) {
            throw new RequestRejected('stream must be boolean');
        }
        $body = new stdClass();
        /** @var mixed $maxTokens */
        $maxTokens = $data->max_tokens ?? null;
        if ($maxTokens !== null) {
            if (!is_int($maxTokens) || $maxTokens < 1) {
                throw new RequestRejected('max_tokens must be a positive integer');
            }
            $body->max_tokens = $maxTokens;
        }
        /** @var mixed $stops */
        $stops = $data->stop_sequences ?? null;
        if ($stops !== null) {
            if (!is_array($stops) || !array_is_list($stops) || array_filter($stops, static fn(mixed $stop): bool => !is_string($stop) || $stop === '') !== []) {
                throw new RequestRejected('stop_sequences must be a list of nonempty strings');
            }
            $body->stop_sequences = $stops;
        }
        $effort = self::effort($data->output_config ?? null);
        /** @var mixed $thinking */
        $thinking = $data->thinking ?? null;
        if ($thinking instanceof stdClass) {
            /** @var mixed $kind */
            $kind = $thinking->type ?? null;
            if ($kind === 'disabled') {
                if (Models::acceptsThinkingDisable($model)) {
                    $body->thinking = self::object(['type' => 'disabled']);
                    // the CLI's edits of thinking in the context are invalid when thinking is off
                    $body->context_management = self::object(['edits' => []]);
                } else {
                    // thinking cannot be turned off there: the lowest effort is the nearest "off"
                    $effort ??= 'low';
                }
            } elseif ($kind === 'adaptive') {
                if (Models::supportsAdaptiveThinking($model)) {
                    $body->thinking = $thinking;
                }
            } else {
                throw new RequestRejected('thinking supports the types adaptive and disabled');
            }
        } elseif ($thinking !== null) {
            throw new RequestRejected('thinking supports the types adaptive and disabled');
        }
        if ($effort !== null) {
            $body->output_config = self::object(['effort' => $effort]);
        }
        $names = new ToolNames();
        $manifest = [];
        $tools = [];
        /** @var mixed $given */
        $given = $data->tools ?? [];
        if (!is_array($given)) {
            throw new RequestRejected('tools must be a list');
        }
        /** @var mixed $tool */
        foreach ($given as $tool) {
            if (!$tool instanceof stdClass || property_exists($tool, 'type') && $tool->type !== 'custom') {
                throw new RequestRejected('Only the tools of the application are supported');
            }
            /** @var mixed $name */
            $name = $tool->name ?? null;
            /** @var mixed $description */
            $description = $tool->description ?? '';
            /** @var mixed $schema */
            $schema = $tool->input_schema ?? self::object(['type' => 'object']);
            if (!is_string($name) || !is_string($description) || !$schema instanceof stdClass) {
                throw new RequestRejected('A tool needs a name, a text description and an object input_schema');
            }
            $native = $names->add($name);
            // the list of the inert server and the body of the request must offer the same shape
            $schema = Schema::normalize($schema);
            $manifest[] = ['name' => $native, 'description' => $description, 'inputSchema' => $schema];
            $offered = ['name' => ToolNames::PREFIX . $native, 'description' => $description, 'input_schema' => $schema];
            if (($tool->eager_input_streaming ?? null) === true) {
                // the application asked for the arguments of a call as the model writes them, and checks them itself
                $offered['eager_input_streaming'] = true;
            }
            $tools[] = $offered;
        }
        $body->tools = $tools;
        $frames = self::frames($data->messages ?? null, $names);
        return new self(
            $model,
            Models::native($model),
            self::system($data->system ?? null),
            $frames,
            json_encode($body, self::JSON),
            $manifest,
            $names,
            $stream,
            $effort,
            is_int($maxTokens) ? $maxTokens : null,
        );
    }

    /**
     * The id of the CLI's session, the same for every request of one conversation: the CLI names a folder of the
     * session near the top of its request, and a new id per call would move the cached prefix every time.
     */
    public function sessionId(): string {
        $bytes = substr(hash('sha256', json_encode([$this->system, $this->frames[0]['message']], self::JSON), true), 0, 16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @param array<string, mixed> $fields */
    private static function object(array $fields): stdClass {
        $object = new stdClass();
        /** @var mixed $value */
        foreach ($fields as $name => $value) {
            $object->{$name} = $value;
        }
        return $object;
    }

    private static function effort(mixed $config): ?string {
        if ($config === null) {
            return null;
        }
        if (!$config instanceof stdClass || array_diff(array_keys(get_object_vars($config)), ['effort']) !== []) {
            throw new RequestRejected('output_config supports effort only');
        }
        /** @var mixed $effort */
        $effort = $config->effort ?? null;
        if ($effort === null) {
            return null;
        }
        if (!is_string($effort) || !in_array($effort, self::EFFORTS, true)) {
            throw new RequestRejected('output_config.effort must be one of: ' . implode(', ', self::EFFORTS));
        }
        return $effort;
    }

    private static function system(mixed $system): string {
        if ($system === null || is_string($system)) {
            return (string) $system;
        }
        if (!is_array($system)) {
            throw new RequestRejected('system must be a text or a list of text blocks');
        }
        $parts = [];
        /** @var mixed $block */
        foreach ($system as $block) {
            /** @var mixed $text */
            $text = $block instanceof stdClass && ($block->type ?? null) === 'text' ? ($block->text ?? null) : null;
            if (!is_string($text)) {
                throw new RequestRejected('system must be a text or a list of text blocks');
            }
            $parts[] = $text;
        }
        return implode("\n\n", $parts);
    }

    /**
     * @return non-empty-list<array{type: string, message: array{role: string, content: list<stdClass>}}>
     */
    private static function frames(mixed $messages, ToolNames $names): array {
        if (!is_array($messages)) {
            throw new RequestRejected('messages must be a list');
        }
        /** @var list<array{type: string, message: array{role: string, content: list<stdClass>}}> $frames */
        $frames = [];
        /** @var mixed $message */
        foreach ($messages as $message) {
            /** @var mixed $role */
            $role = $message instanceof stdClass ? ($message->role ?? null) : null;
            if (!$message instanceof stdClass || ($role !== 'user' && $role !== 'assistant')) {
                throw new RequestRejected('A message needs the role user or assistant');
            }
            $blocks = self::blocks($message->content ?? null, $names);
            $before = $frames === [] ? null : $frames[count($frames) - 1];
            if ($role === 'user' && $before !== null && $before['type'] === 'user') {
                // replies of the user in a row are one frame
                $blocks = [...$before['message']['content'], ...$blocks];
                array_pop($frames);
            }
            $frames[] = ['type' => $role, 'message' => ['role' => $role, 'content' => $blocks]];
        }
        if ($frames === []) {
            throw new RequestRejected('The history must end with a nonempty message of the user or a result of a tool');
        }
        $last = $frames[count($frames) - 1];
        if ($last['type'] !== 'user' || $last['message']['content'] === []) {
            throw new RequestRejected('The history must end with a nonempty message of the user or a result of a tool; a prefilled answer is not supported');
        }
        self::normalizeToolIds($frames);
        return $frames;
    }

    /** @return list<stdClass> */
    private static function blocks(mixed $content, ToolNames $names): array {
        if (is_string($content)) {
            return $content === '' ? [] : [self::object(['type' => 'text', 'text' => $content])];
        }
        if (!is_array($content)) {
            throw new RequestRejected('The content of a message must be a text or a list of blocks');
        }
        $blocks = [];
        /** @var mixed $block */
        foreach ($content as $block) {
            /** @var mixed $type */
            $type = $block instanceof stdClass ? ($block->type ?? null) : null;
            if (!$block instanceof stdClass || !is_string($type) || !in_array($type, self::BLOCKS, true)) {
                throw new RequestRejected('Unsupported content block: ' . (is_string($type) ? $type : 'not an object'));
            }
            unset($block->cache_control);
            if ($type === 'tool_use') {
                /** @var mixed $name */
                $name = $block->name ?? null;
                if (!is_string($name)) {
                    throw new RequestRejected('A call of a tool needs a name');
                }
                $block->name = $names->sent($name);
                if (!(($block->input ?? null) instanceof stdClass)) {
                    $block->input = new stdClass();
                }
            } elseif ($type === 'tool_result') {
                /** @var mixed $nested */
                $nested = $block->content ?? null;
                /** @var mixed $inner */
                foreach (is_array($nested) ? $nested : [] as $inner) {
                    if ($inner instanceof stdClass) {
                        unset($inner->cache_control);
                    }
                }
            }
            $blocks[] = $block;
        }
        return $blocks;
    }

    /**
     * The API refuses an id with characters of another provider (a colon, for one). Ids of its own form stay; the
     * rest get a stable id from their hash, the same in a call and in its result.
     *
     * @param list<array{type: string, message: array{role: string, content: list<stdClass>}}> $frames
     */
    private static function normalizeToolIds(array $frames): void {
        $references = [];
        foreach ($frames as $frame) {
            foreach ($frame['message']['content'] as $block) {
                $field = match ($block->type ?? null) {
                    'tool_use' => 'id',
                    'tool_result' => 'tool_use_id',
                    default => null,
                };
                if ($field === null) {
                    continue;
                }
                /** @var mixed $id */
                $id = $block->{$field} ?? null;
                if (!is_string($id) || $id === '') {
                    throw new RequestRejected('The ids of calls and results of tools must be nonempty strings');
                }
                $references[] = [$block, $field, $id];
            }
        }
        $used = [];
        $foreign = [];
        foreach ($references as [, , $id]) {
            if (preg_match('~^[a-zA-Z0-9_-]+$~', $id) === 1) {
                $used[$id] = true;
            } else {
                $foreign[] = $id;
            }
        }
        $foreign = array_values(array_unique($foreign));
        sort($foreign, SORT_STRING);
        $mapping = [];
        foreach ($foreign as $id) {
            $base = 'toolu_mercurius_' . substr(hash('sha256', $id), 0, 32);
            $candidate = $base;
            for ($suffix = 1; isset($used[$candidate]); $suffix++) {
                $candidate = $base . '_' . $suffix;
            }
            $mapping[$id] = $candidate;
            $used[$candidate] = true;
        }
        foreach ($references as [$block, $field, $id]) {
            if (isset($mapping[$id])) {
                $block->{$field} = $mapping[$id];
            }
        }
    }
}
