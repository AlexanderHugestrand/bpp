<?php

require_once __DIR__.'/string_functions.php';

class HistoryString {
    private $edits = [];
    private $cachedResult = '';

    public function __construct(string $string) {
        $this->edits[] = [
            'from' => 0,
            'to' => 0,
            'replacement' => $string,
            'owner' => null
        ];
        $this->cachedResult = $string;
    }

    // Returns the history index of the replacement.
    public function replace(int $from, int $to, string $replacement, object $owner) {
        $this->edits[] = [
            'from' => $from,
            'to' => $to,
            'replacement' => $replacement,
            'owner' => $owner
        ];

        $this->cachedResult = strReplace($this->cachedResult, $from, $to, $replacement);

        return count($this->edits) - 1;
    }

    public function getEditCount() {
        return count($this->edits);
    }

    public function getLastHistoryIndexOf(object &$owner) {
        for ($i = count($this->edits) - 1; $i >= 0; --$i) {
            if ($this->edits[$i]['owner'] === $owner) {
                return $i;
            }
        }
        return false;
    }

    // Checks if the boundaries (start, end) of $replacementIndex equals $start, $start + $length,
    // after applying any subsequent replacements.
    public function equivalent(int $replacementIndex, int $start, int $length) {
        // Lookup the old replacement.
        $replacementIndex = $this->mod($replacementIndex, count($this->edits));
        $replacementStart = $this->edits[$replacementIndex]['from'];
        $replacementEnd = $replacementStart + strlen($this->edits[$replacementIndex]['replacement']);
        
        // Transform the replacement's bounds.
        for ($i = $replacementIndex + 1; $i < count($this->edits); ++$i) {
            $from = $this->edits[$i]['from'];
            $to = $this->edits[$i]['to'];

            $overlapStart = Math.max($start, $from);
            $overlapEnd = Math.min($end, $to);
            $overlaps = $overlapEnd > $overlapStart;
            $encloses = $replacementStart <= $from && $replacementEnd >= $to;

            if ($overlaps && !$encloses) {
                // Either $replacementStart or $replacementEnd doesn't exist anymore, and cannot be computed.
                return false;
            }

            $replacement = $this->edits[$i]['replacement'];
            $diff = strlen($replacement) - ($to - $from);
            if ($encloses) {
                $replacementEnd += $diff;
            } else if ($replacementStart >= $to) {
                $replacementStart += $diff;
                $replacementEnd += $diff;
            }
        }

        // The end value to compare with.
        $end = $start + $length;
        return $replacementStart === $start && $replacementEnd === $end;
    }

    public function __toString() {
        return $this->cachedResult;
    }

    private function mod(int $x, int $y) {
        if ($y < 0) {
            return -$this->mod($x, -$y);
        }

        if ($x < 0) {
            $m = (-$x) % $y;
            if ($m == 0) {
                return 0;
            }
            return $y - $m;
        } else {
            return $x % $y;
        }
    }
}