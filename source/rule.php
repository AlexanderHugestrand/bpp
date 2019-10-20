<?php

require_once __DIR__.'/preprocessor.php';
require_once __DIR__.'/history_string.php';
require_once __DIR__.'/rule_block.php';
require_once __DIR__.'/rule_builtin_macro.php';
require_once __DIR__.'/rule_composite.php';
require_once __DIR__.'/rule_const.php';
require_once __DIR__.'/rule_macro.php';
require_once __DIR__.'/rule_regex.php';

abstract class Rule {
    private $preprocessor;

    public function __construct(Preprocessor $preprocessor) {
        $this->preprocessor = $preprocessor;
    }

    public function currentLineStr() {
        return $this->preprocessor->currentLineStr();
    }

    public function applyRules(string $str, bool $allowSideEffects, string $caller) {
        return $this->preprocessor->applyRules($str, $allowSideEffects, $caller);
    }

    public function __toString() {
        return $this->getSignature();
    }

    abstract public function getName();
    abstract public function getSignature();

    abstract public function beginSearch();
    abstract public function findMatch(HistoryString $haystack, int $offset = 0);
    abstract public function endSearch();

    public static function create(Preprocessor $preprocessor, string $line) {
        $f = self::parseFields($preprocessor, $line);
        return self::createFromFields($preprocessor, $f);
    }

    // Splits the rule definition into two or three fields.
    public static function parseFields(Preprocessor $preprocessor, string $ruleDefinition) {
        switch ($ruleDefinition[0]) {
            case '[': { // Composite
                $endPos = strBlockEndPos($ruleDefinition, 0, ['['], [']']);
                if ($endPos < 0) {
                    echo "Error: Invalid composite rule definition, missing ')', ".$preprocessor->currentLineStr()."\n";
                    return false;
                }
                return [
                    'type' => 'composite',
                    'head' => substr($ruleDefinition, 0, $endPos + 1),
                    'body' => trim(substr($ruleDefinition, $endPos + 1))
                ];
            } break;
            case '(': { // Block
                $endPos = strBlockEndPos($ruleDefinition, 0, ['('], [')']);
                if ($endPos < 0) {
                    echo "Error: Invalid block definition, missing ')', ".$preprocessor->currentLineStr()."\n";
                    return false;
                }
                return [
                    'type' => 'block',
                    'head' => substr($ruleDefinition, 0, $endPos + 1),
                    'body' => trim(substr($ruleDefinition, $endPos + 1))
                ];
            } break;
            case '/': { // Regex
                $pos = strCharPos($ruleDefinition, 1, '/');
                return [
                    'type' => 'regex',
                    'head' => substr($ruleDefinition, 0, $pos + 1),
                    'body' => trim(substr($ruleDefinition, $pos + 1))
                ];
            } break;
        }

        if (!ctype_alpha($ruleDefinition[0]) && $ruleDefinition[0] !== '_') {
            echo "Error: Invalid definition, ".$preprocessor->currentLineStr()."\n";
            return false;
        }

        // Macro or constant.
        $length = strlen($ruleDefinition);

        $pos = strNonIdentifierPos($ruleDefinition, 0);
        $name = substr($ruleDefinition, 0, $pos);

        $pos = strNonWhitespacePos($ruleDefinition, $pos);
        if ($pos > $length) {
            echo "Error: Invalid definition, ".$preprocessor->currentLineStr()."\n";
            return false;
        }

        if ($pos < $length && $ruleDefinition[$pos] === '(') {
            // Macro.
            $endPos = strBlockEndPos($ruleDefinition, $pos, ['(', '['], [')', ']']);
            if ($endPos < 0) {
                echo "Error: Invalid macro definition, missing ')', ".$preprocessor->currentLineStr()."\n";
                return false;
            }

            // Exception to the rule of 'head' and 'body'. The 'head' of a macro is its name and parameters,
            // but it's better to split the head up further into name and params.
            return [
                'type' => 'macro',
                'name' => $name,
                'params' => strCut($ruleDefinition, $pos, $endPos + 1),
                'body' => trim(substr($ruleDefinition, $endPos + 1))
            ];
        } else {
            // Constant
            return [
                'type' => 'const',
                'head' => $name,
                'body' => trim(substr($ruleDefinition, $pos))
            ];
        }
    }

    public static function createFromFields(Preprocessor $preprocessor, array $fieldArray) {
        switch ($fieldArray['type']) {
            case 'composite': {
                $paramStrings = strArgExplode(',', strCut($fieldArray['head'], 1, -1), ['[','('], [']',')']);
                $params = [];
                foreach ($paramStrings as $paramString) {
                    $params[] = self::create($preprocessor, $paramString);
                    if (end($params) === false) {
                        echo "Error: Rule factory failed on '$paramString', ".$preprocessor->currentLineStr()."\n";
                        return false;
                    }
                }
                return new CompositeRule($preprocessor, $fieldArray['head'], $params, $fieldArray['body']);
            }
            case 'block': {
                $params = strArgExplode(',', strCut($fieldArray['head'], 1, -1), ['('], [')']);
                if (count($params) !== 2) {
                    echo "Error: Wrong number of arguments. #block requires two parameters, ".count($params)." given.";
                    echo $preprocessor->currentLineStr()."\n";
                    return false;
                }
                return new BlockRule($preprocessor, $fieldArray['head'], $params[0], $params[1], $fieldArray['body']);
            }
            case 'regex': {
                return new RegexRule($preprocessor, $fieldArray['head'], $fieldArray['body']);
            }
            case 'macro': {
                $params = strArgExplode(',', strCut($fieldArray['params'], 1, -1), ['(', '['], [')', ']']);
                return new MacroRule($preprocessor, $fieldArray['name'], $params, $fieldArray['body']);
            }
            case 'const': {
                return new ConstRule($preprocessor, $fieldArray['head'], $fieldArray['body']);
            }
            default: {
                echo "Error: Invalid definition, ".$preprocessor->currentLineStr()."\n";
                return false;
            }
        }
    }
}

abstract class RuleMatch {
    private $rule;
    private $pos;
    private $length;

    public function __construct(Rule $rule, int $pos, int $length) {
        $this->rule = $rule;
        $this->pos = $pos;
        $this->length = $length;
    }

    public function getRule() {
        return $this->rule;
    }
    
    public function getPos() {
        return $this->pos;
    }

    public function getEnd() {
        return $this->pos + $this->length;
    }

    public function getLength() {
        return $this->length;
    }

    public function __toString() {
        return 'Match of '.$this->rule->getSignature()." @[".$this->pos.", ".$this->getEnd()."]\n";
    }

    abstract public function getArgument($indexOrName);

    // Returns the next position to exceed, while searching for rule matches (in Preprocessor.applyRules()).
    // Usually this is just $this->pos, since the rules should be applied to the expanded text too.
    // But in some cases (like in the builtin rule #define()), you want to skip the whole body and
    // instead return $this->pos + $this->length.
    abstract public function applyTo(HistoryString &$hString);
}
