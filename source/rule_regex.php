<?php

require_once __DIR__.'/rule.php';

class RegexRule extends Rule {
    private $pattern;
    private $body;

    private $matchStack = [];

    public function __construct(Preprocessor $preprocessor, string $pattern, string $body) {
        parent::__construct($preprocessor);
        $this->pattern = $pattern;
        $this->body = $body;
    }

    public function getName() {
        return $this->pattern;
    }

    public function getSignature() {
        return $this->pattern;
    }

    public function getPattern() {
        return $this->pattern;
    }

    public function getBody() {
        return $this->body;
    }

    public function beginSearch() {
        $this->matchStack[] = null;
    }

    public function endSearch() {
        array_pop($this->matchStack);
    }
    
    public function findMatch(HistoryString $haystack, int $offset = 0) {
        $matches = $this->getMatches($haystack, $offset);
        if (empty($matches)) {
            return false;
        }

        $matchParts = $matches[0];
        $fullMatch = $matchParts[0];

        $start = $fullMatch['pos'];
        $length = strlen($fullMatch['string']);
        $end = $start + $length;

        $lastMatch = end($this->matchStack);
        if ($lastMatch === null || $lastMatch === false || !$lastMatch->hasBeenApplied() || !$lastMatch->isEquivalentTo($haystack, $start, $length)) {
            $lastMatch = new RegexMatch($this, $start, $length, $matchParts);
            $this->matchStack[count($this->matchStack) - 1] = $lastMatch;
            return $lastMatch;
        } else {
            $lastStart = $lastMatch->getPos();
            $lastEnd = $lastMatch->getEnd();
            
            // Move forward until we find a match outside the bounds of $lastMatch.
            // Reason: Otherwise this rule will match its own results infinitely many times,
            // and we'll get stuck in an infinite loop.
            $nextMatchIndex = 1;
            while (max($lastStart, $start) < min($lastEnd, $end)) {
                if ($nextMatchIndex >= count($matches)) {
                    return false;
                }
                $matchParts = $matches[$nextMatchIndex];
                $fullMatch = $matchParts[0];
                $start = $fullMatch['pos'];
                $length = strlen($fullMatch['string']);
                $end = $start + $length;
                ++$nextMatchIndex;
            }

            $lastMatch = new RegexMatch($this, $start, $length, $matchParts);
            $this->matchStack[count($this->matchStack) - 1] = $lastMatch;
            return $lastMatch;
        }
    }

    private function getMatches(string $haystack, int $offset) {
        $matchArray = [];
        $r = preg_match_all($this->pattern, $haystack, $matchArray, PREG_OFFSET_CAPTURE, $offset);
        if ($r === false || $r === 0) {
            return [];
        }

        $matchesOut = [];
        foreach ($matchArray as $level) {
            for ($i = 0; $i < count($level); ++$i) {
                if (!isset($matchesOut[$i])) {
                    $matchesOut[$i] = [];
                }
                $matchesOut[$i][] = [
                    'string' => $level[$i][0],
                    'pos' => $level[$i][1]
                ];
            }
        }
        return $matchesOut;
    }
}

class RegexMatch extends RuleMatch {
    private $args = [];
    private $replacement;
    private $replacementIndex = -1;

    public function __construct(RegexRule $rule, int $pos, int $length, array $matches) {
        parent::__construct($rule, $pos, $length);

        $body = $this->getRule()->getBody();

        for ($i = 0; $i < count($matches); ++$i) {
            $paramName = "#$i";
            $argumentValue = $matches[$i]['string'];
            $this->args[] = $argumentValue;

            $pos = 0;
            $out = '';
            $regex = '/(^|[^a-zA-Z0-9_])('.$paramName.')($|[^a-zA-Z0-9_])/';
            $paramMatches = [];
            if (preg_match_all($regex, $body, $paramMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($paramMatches[2] as $match) {
                    $out .= substr($body, $pos, $match[1]);
                    $out .= $argumentValue;
                    $pos = $match[1] + strlen($paramName);
                }
            }
            $out .= substr($body, $pos);
            $body = $out;
        }

        $this->replacement = $body;
    }

    public function hasBeenApplied() {
        return $this->replacementIndex >= 0;
    }

    public function getArgument($index) {
        if ($index[0] === '#') {
            $index = substr($index, 1);
        }
        return $this->args[$index];
    }

    public function applyTo(HistoryString &$hString, bool $allowSideEffects) {
        $this->replacementIndex = $hString->replace($this->getPos(), $this->getEnd(), $this->replacement, $this);
        return $this->getPos();
    }

    public function getReplacementIndex() {
        return $this->replacementIndex;
    }

    public function isEquivalentTo(HistoryString $hString, int $start, int $length) {
        $hString->equivalent($this->replacementIndex, $start, $length);
    }
}
