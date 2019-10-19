<?php

require_once __DIR__.'/rule.php';
require_once __DIR__.'/string_functions.php';

class BlockRule extends Rule {
    private $identifier;
    private $openingString;
    private $closingString;
    private $body;

    public function __construct(Preprocessor $preprocessor, string $identifier, string $openingString, string $closingString, string $body) {
        parent::__construct($preprocessor);
        $this->identifier = strNormalizeSpaces($identifier);
        $this->openingString = $openingString;
        $this->closingString = $closingString;
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

    public function beginSearch() {}

    public function findMatch(HistoryString $haystack, int $offset = 0) {
        $pos = strpos($haystack, $this->openingString, $offset);
        if ($pos === false) {
            return false;
        }
        $endPos = strBlockEndPos($haystack, $pos, [$this->openingString], [$this->closingString]);
        if ($endPos < 0) {
            return false;
        }
        return new BlockMatch($this, $pos, $endPos + 1 - $pos, substr($haystack, $pos, $endPos + 1 - $pos));
    }

    public function endSearch() {}
}

class BlockMatch extends RuleMatch {
    private $block = '';
    private $replacement = '';

    public function __construct(BlockRule $rule, int $startPos, int $length, string $matchedBlock) {
        parent::__construct($rule, $startPos, $length);
        $this->block = $matchedBlock;

        $body = $this->getRule()->getBody();

        $pos = 0;
        $out = '';
        $regex = '/(^|[^a-zA-Z0-9_])(__BLOCK__)($|[^a-zA-Z0-9_])/';
        $matches = [];
        if (preg_match_all($regex, $body, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as $match) {
                $out .= substr($body, $pos, $match[1]);
                $out .= $matchedBlock;
                $pos = $match[1] + strlen($match[0]);
            }
        }
        $out .= substr($body, $pos);
        $this->replacement = $out;
    }

    public function getArgument($indexOrName) {
        if ($indexOrName === '__BLOCK__') {
            return $this->block;
        } else {
            return '';
        }
    }
    
    public function applyTo(HistoryString &$hString) {
        $hString->replace($this->getPos(), $this->getEnd(), $this->replacement, $this);
        return $this->getPos();
    }
}
