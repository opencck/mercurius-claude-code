<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use JsonException;
use stdClass;

/**
 * The answer of the API as it streams through the relay: the events go on to the application with its own names of
 * tools, and the whole message is put together for a request without a stream. An answer is complete only when the
 * stream reached its end with a reason of the stop: a cut stream is an error, not an answer.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/admission.py@269ddcb:L566-L637 (Capture) —
 *      the same assembly of blocks, deltas and usage. Arguments of a call that do not parse make the answer
 *      incomplete here instead of dropping the call: the application has the events and decides itself
 */
final class Capture {
    private string $pending = '';
    private ?stdClass $head = null;
    /** @var array<int, stdClass> */
    private array $blocks = [];
    /** @var array<int, string> */
    private array $arguments = [];
    private bool $broken = false;
    private bool $complete = false;

    public function __construct(private readonly ToolNames $names) {}

    public function complete(): bool {
        return $this->complete;
    }

    /** The whole message; null until the stream gave its start. */
    public function message(): ?stdClass {
        if ($this->head === null) {
            return null;
        }
        $message = clone $this->head;
        ksort($this->blocks);
        $message->content = array_values($this->blocks);
        return $message;
    }

    /**
     * @return list<string> the events for the application, each a whole frame of the stream
     * @throws JsonException the stream is not what the API sends
     */
    public function feed(string $chunk): array {
        $this->pending .= $chunk;
        $frames = [];
        while (preg_match('~\r?\n\r?\n~', $this->pending, $match, PREG_OFFSET_CAPTURE) === 1) {
            $frame = substr($this->pending, 0, $match[0][1]);
            $this->pending = substr($this->pending, $match[0][1] + strlen($match[0][0]));
            $data = [];
            foreach (preg_split('~\r?\n~', $frame) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5), ' ');
                }
            }
            if ($data === []) {
                $frames[] = $frame . "\n\n";
                continue;
            }
            /** @var mixed $event */
            $event = json_decode(implode("\n", $data), false, 512, JSON_THROW_ON_ERROR);
            /** @var mixed $type */
            $type = $event instanceof stdClass ? ($event->type ?? null) : null;
            if (!$event instanceof stdClass || !is_string($type)) {
                throw new JsonException('An event of the stream is not an object with a type');
            }
            $frames[] = $this->event($type, $event) ? sprintf("event: %s\ndata: %s\n\n", $type, json_encode($event, MessagesRequest::JSON)) : $frame . "\n\n";
        }
        return $frames;
    }

    /** @return bool the event was changed and goes on in the new form */
    private function event(string $type, stdClass $event): bool {
        /** @var mixed $index */
        $index = $event->index ?? null;
        switch ($type) {
            case 'message_start':
                /** @var mixed $message */
                $message = $event->message ?? null;
                if ($message instanceof stdClass) {
                    $head = clone $message;
                    /** @var mixed $usage */
                    $usage = $head->usage ?? null;
                    if ($usage instanceof stdClass) {
                        $head->usage = clone $usage;
                    }
                    $this->head = $head;
                }
                return false;
            case 'content_block_start':
                /** @var mixed $block */
                $block = $event->content_block ?? null;
                if (!is_int($index) || !$block instanceof stdClass) {
                    return false;
                }
                $changed = false;
                /** @var mixed $name */
                $name = $block->name ?? null;
                if (($block->type ?? null) === 'tool_use' && is_string($name)) {
                    $restored = $this->names->restored($name);
                    $changed = $restored !== $name;
                    $block->name = $restored;
                }
                $this->blocks[$index] = clone $block;
                return $changed;
            case 'content_block_delta':
                /** @var mixed $delta */
                $delta = $event->delta ?? null;
                if (!is_int($index) || !$delta instanceof stdClass || !isset($this->blocks[$index])) {
                    return false;
                }
                $block = $this->blocks[$index];
                $field = match ($delta->type ?? null) {
                    'text_delta' => 'text',
                    'thinking_delta' => 'thinking',
                    'signature_delta' => 'signature',
                    default => null,
                };
                if ($field !== null) {
                    /** @var mixed $had */
                    $had = $block->{$field} ?? '';
                    /** @var mixed $more */
                    $more = $delta->{$field} ?? '';
                    $block->{$field} = (is_string($had) ? $had : '') . (is_string($more) ? $more : '');
                } elseif (($delta->type ?? null) === 'input_json_delta') {
                    /** @var mixed $part */
                    $part = $delta->partial_json ?? '';
                    $this->arguments[$index] = ($this->arguments[$index] ?? '') . (is_string($part) ? $part : '');
                } elseif (($delta->type ?? null) === 'citations_delta') {
                    /** @var mixed $citations */
                    $citations = $block->citations ?? [];
                    $block->citations = [...(is_array($citations) ? array_values($citations) : []), $delta->citation ?? null];
                }
                return false;
            case 'content_block_stop':
                if (is_int($index) && isset($this->arguments[$index], $this->blocks[$index])) {
                    // a call without arguments streams one empty piece
                    $raw = trim($this->arguments[$index]);
                    unset($this->arguments[$index]);
                    /** @var mixed $input */
                    $input = $raw === '' ? new stdClass() : json_decode($raw, false);
                    if ($input instanceof stdClass) {
                        $this->blocks[$index]->input = $input;
                    } else {
                        $this->broken = true;
                    }
                }
                return false;
            case 'message_delta':
                if ($this->head === null) {
                    return false;
                }
                /** @var mixed $delta */
                $delta = $event->delta ?? null;
                /** @var mixed $value */
                foreach ($delta instanceof stdClass ? get_object_vars($delta) : [] as $key => $value) {
                    $this->head->{$key} = $value;
                }
                /** @var mixed $total */
                $total = $this->head->usage ?? null;
                if (!$total instanceof stdClass) {
                    $total = new stdClass();
                    $this->head->usage = $total;
                }
                /** @var mixed $usage */
                $usage = $event->usage ?? null;
                /** @var mixed $value */
                foreach ($usage instanceof stdClass ? get_object_vars($usage) : [] as $key => $value) {
                    $total->{$key} = $value;
                }
                return false;
            case 'message_stop':
                $this->complete = $this->head !== null && is_string($this->head->stop_reason ?? null) && $this->arguments === [] && !$this->broken;
                return false;
            default:
                return false;
        }
    }
}
