<?php

require_once __DIR__.'/rule.php';

class ConstRule extends Rule {
    private $token;
    private $replacement;

    public function __construct(Preprocessor $preprocessor, string $token, string $replacement) {
        parent::__construct($preprocessor);
        $this->token = $token;
        $this->replacement = $replacement;
    }

    public function getName() {
        return $this->token;
    }

    public function getSignature() {
        return $this->token;
    }

    public function getToken() {
        return $this->token;
    }

    public function getReplacement() {
        return $this->replacement;
    }

    public function beginSearch() {}
    public function endSearch() {}

    public function findMatch(HistoryString $haystack, int $offset = 0) {
        $regex = '/(^|[^a-zA-Z0-9_])('.$this->token.')($|[^a-zA-Z0-9_])/';
        $matches = [];
        if (preg_match_all($regex, $haystack, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            return new ConstMatch($this, $matches[2][0][1]);
        }
        return false;
    }
}

class ConstMatch extends RuleMatch {
    public function __construct(ConstRule $rule, int $pos) {
        parent::__construct($rule, $pos, strlen($rule->getToken()));
    }

    public function getArgument($index) {
        return '';
    }

    public function applyTo(HistoryString &$hString) {
        $hString->replace($this->getPos(), $this->getEnd(), $this->getRule()->getReplacement(), $this);
        return $this->getPos();
    }
}
