<?php

require_once __DIR__.'/rule.php';

class CompositeRule extends Rule {
    private $identifier;
    private $rules;
    private $body;

    public function __construct(Preprocessor $preprocessor, string $identifier, array $rules, string $body) {
        parent::__construct($preprocessor);
        $this->identifier = strNormalizeSpaces($identifier);
        $this->rules = $rules;
        $this->body = $body;
    }

    public function getName() {
        return $this->identifier;
    }

    public function getSignature() {
        return $this->identifier;
    }

    public function getBody() {
        return $this->body;
    }

    public function getRules() {
        return $this->rules;
    }

    public function beginSearch() {}
    
    public function findMatch(HistoryString $haystack, int $offset = 0) {
        $pos = $offset;
        $matches = [];
        foreach ($this->rules as $rule) {
            $match = $rule->findMatch($haystack, $pos);
            if ($match === false) {
                return false;
            }

            $inBetweenMatches = trim(substr($haystack, $pos, $match->getPos() - $pos));
            if ($inBetweenMatches !== '' && !empty($matches)) {
                $matches = [];
                $matches[] = $match;
                //echo "Mismatched #2 rule $rule from pos $pos:\n'".$inBetweenMatches."'\n";
            }

            $matches[] = $match;
            $pos = $match->getEnd();
        }

        $startPos = $matches[0]->getPos();
        $endPos = end($matches)->getEnd();
        $length = $endPos - $startPos;

        return new CompositeRuleMatch($this, $startPos, $length, $matches);
    }

    public function endSearch() {}
}

class CompositeRuleMatch extends RuleMatch {
    private $matches = [];
    private $replacement = '';
    public function __construct(CompositeRule $rule, int $pos, int $length, array $matches) {
        parent::__construct($rule, $pos, $length);
        $this->matches = $matches;

        $body = $rule->getBody();
        $rules = $rule->getRules();

        $pos = 0;
        $out = '';
        while (true) {
            $regexMatches = [];
            if (!preg_match('/(^|.)(#[0-9]+)($|[^0-9])/', $body, $regexMatches, PREG_OFFSET_CAPTURE, $pos)) {
                break;
            }
            $matchStr = $regexMatches[0][0];
            $matchPos = $regexMatches[0][1];
            
            if ($matchStr[0] !== '#') {
                $matchStr = substr($matchStr, 1);
                $matchPos += 1;
            }

            $out .= substr($body, $pos, $matchPos - $pos);

            $indexEnd = strNonNumericalPos($body, $matchPos + 1);
            $ruleIndex = substr($body, $matchPos + 1, $indexEnd - $matchPos - 1);
            
            $pos = $indexEnd;

            if ($body[$indexEnd] !== '[') {
                continue;
            }

            $blockEnd = strBlockEndPos($body, $indexEnd, ['['], [']']);
            $blockContents = trim(substr($body, $indexEnd + 1, $blockEnd - $indexEnd - 1));
            $out .= $this->matches[$ruleIndex]->getArgument($blockContents);
            $pos = $blockEnd + 1;
        }

        $out .= substr($body, $pos);
        $this->replacement = $out;
    }

    public function getArgument($nameOrIndex) {
        if ($nameOrIndex[0] !== '#') {
            return '';
        }
        $indexEnd = strNonNumericalPos($nameOrIndex, 1);
        $ruleIndex = substr($nameOrIndex, 0, $indexEnd);

        if ($nameOrIndex[$indexEnd] !== '[') {
            return '';
        }

        $blockEnd = strBlockEndPos($body, $indexEnd, ['['], [']']);
        $blockContents = trim(substr($body, $indexEnd + 1, $blockEnd));
        return $this->matches[$ruleIndex]->getArgument($blockContents);
    }

    public function applyTo(HistoryString &$hString, bool $allowSideEffects) {
        $hString->replace($this->getPos(), $this->getEnd(), $this->replacement, $this);
        return $this->getPos();
    }
}