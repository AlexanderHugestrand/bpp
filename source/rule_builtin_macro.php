<?php

require_once __DIR__.'/rule.php';

class BuiltinMacroRule extends Rule {
    private $name;
    private $signature;
    private $parameters = [];
    private $func;
    private $progressFunc;

    public function __construct(Preprocessor $preprocessor, 
                                string $name, 
                                array $parameters, 
                                callable $func, 
                                callable $progressFunc = null)
    {
        parent::__construct($preprocessor);
        $this->name = $name;
        $this->signature = strNormalizeSpaces($name.'('.implode(', ', $parameters).')');
        $this->parameters = $parameters;
        $this->func = $func;
        if ($progressFunc === null) {
            $this->progressFunc = function (BuiltinMacroMatch $match, string $replacement) {
                return $match->getPos();
            };
        } else {
            $this->progressFunc = $progressFunc;
        }
    }

    public function getName() {
        return $this->name;
    }

    public function getSignature() {
        return $this->signature;
    }

    public function getParameters() {
        return $this->parameters;
    }

    public function applyToArgs(array $args) {
        $func = $this->func;
        return $func($args);
    }

    public function beginSearch() {}
    public function endSearch() {}
    
    public function findMatch(HistoryString $haystack, int $offset = 0) {
        $pos = $offset;
        while (true) {
            $regex = '/(^|[^a-zA-Z0-9_])('.$this->name.')($|[^a-zA-Z0-9_])/';
            $matches = [];
            if (!preg_match_all($regex, $haystack, $matches, PREG_OFFSET_CAPTURE, $pos)) {
                return false;
            }
            $pos = $matches[2][0][1];

            $argPos = strNonWhitespacePos($haystack, $pos + strlen($this->name));
            if ($argPos >= strlen($haystack)) {
                return false;
            }

            if (((string) $haystack)[$argPos] === '(') {
                $endPos = strBlockEndPos($haystack, $argPos, ['(', '['], [')', ']']);
                if ($endPos < 0) {
                    return false;
                }

                $argStr = substr($haystack, $argPos + 1, $endPos - ($argPos + 1));
                $args = strArgExplode(',', $argStr, ['(', '['], [')', ']']);

                if (count($args) === count($this->parameters)) {
                    return new BuiltinMacroMatch($this, $pos, $endPos + 1 - $pos, $args);
                } else if (count($this->parameters) > 0 && count($args) >= count($this->parameters) && end($this->parameters) === '...') {
                    return new BuiltinMacroMatch($this, $pos, $endPos + 1 - $pos, $args);
                }

                $pos = $endPos + 1;
            } else {
                $pos = $argPos;
            }
        }
    }

    public function getProgressFunc() {
        return $this->progressFunc;
    }
}

class BuiltinMacroMatch extends RuleMatch {
    private $arguments;

    public function __construct(BuiltinMacroRule $rule, int $pos, int $length, array $arguments) {
        parent::__construct($rule, $pos, $length);
        $this->arguments = $arguments;
    }

    public function getRuleName() {
        return $this->getRule()->getName();
    }

    public function getRuleSignature() {
        return $this->getRule()->getSignature();
    }

    public function getArguments() {
        return $this->arguments;
    }

    public function getNamedArguments() {
        $params = $this->getRule()->getParameters();
        $out = [];
        for ($i = 0; $i < count($params); ++$i) {
            $name = $params[$i];
            if ($i == count($params) - 1 && $name === '...') {
                $out[$name] = array_slice($this->arguments, $i);
            } else {
                $out[$name] = $this->arguments[$i];
            }
        }
        return $out;
    }

    public function getArgument($indexOrName) {
        if (isset($this->arguments[$indexOrName])) {
            return $this->arguments[$indexOrName];
        } else {
            $namedArgs = $this->getNamedArguments();
            if (isset($namedArgs[$indexOrName])) {
                return $namedArgs[$indexOrName];
            }
            return '';
        }
    }

    public function applyTo(HistoryString &$hString, bool $allowSideEffects) {
        $rule = $this->getRule();
        $args = $this->getArguments();
        
        for ($i = 0; $i < count($args); ++$i) {
            $args[$i] = $rule->applyRules($args[$i], $allowSideEffects, 'Arg of builtin '.$rule->getSignature());
        }

        $replacement = $rule->applyToArgs($args);
        if ($replacement === null) {
            $replacement = '';
        }
        $hString->replace($this->getPos(), $this->getEnd(), $replacement, $this);
        $progressFunc = $rule->getProgressFunc();
        return $progressFunc($this, $replacement);
    }
}
