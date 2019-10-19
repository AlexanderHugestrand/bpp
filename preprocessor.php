<?php

require_once __DIR__.'/string_functions.php';
require_once __DIR__.'/rule.php';
require_once __DIR__.'/math.php';

class PreprocessorFileContext {
    private $file = '';
    private $line = 0;

    // Evaluated values of if statements, which are currently surrounding the position
    // we are parsing.
    private $ifValues = [];
    private $currentValue = true;

    public function __construct(string $file) {
        $this->file = $file;
    }

    public function getFile() {
        return $this->file;
    }

    public function getCurrentLine() {
        return $this->line;
    }

    public function setCurrentLine(int $line) {
        $this->line = $line;
    }

    public function currentLineStr() {
        return '@line '.$this->line.' in '.$this->file;
    }

    public function pushIfValue(bool $value) {
        $this->ifValues[] = $value;
        if ($value === false) {
            $this->currentValue = false;
        }
    }

    public function popIfValue() {
        if (array_pop($this->ifValues) === false) {
            $this->currentValue = true;
            foreach ($this->ifValues as $ifValue) {
                if ($ifValue === false) {
                    $this->currentValue = false;
                    break;
                }
            }
        }
    }

    public function getCurrentIfValue() {
        return $this->currentValue;
    }
}

class PreprocessorRuleContext {
    private $preprocessor;
    private $rules = [];
    private $allowSideEffectsStack = [ true ];
    private $allowSideEffects = true;

    public function __construct(Preprocessor $preprocessor) {
        $this->preprocessor = $preprocessor;
        
        // Arithmetic operations.
        $this->addRule('#add', ['A', 'B'], false, function (array $args) { return $args[0] + $args[1]; });
        $this->addRule('#sub', ['A', 'B'], false, function (array $args) { return $args[0] - $args[1]; });
        $this->addRule('#mul', ['A', 'B'], false, function (array $args) { return $args[0] * $args[1]; });
        $this->addRule('#div', ['A', 'B'], false, function (array $args) { return $args[0] / $args[1]; });
        $this->addRule('#idiv', ['A', 'B'], false, function (array $args) { return (int) ($args[0] / $args[1]); });
        $this->addRule('#mod', ['A', 'B'], false, function (array $args) { return $this->mod($args[0], $args[1]); });

        // String operations.
        $this->addRule('#substr', ['STR', 'START', 'END'], false, function (array $args) {
            $string = $args[0];
            $length = strlen($string);
            $start = mod($args[1], $length);
            $end = mod($args[2], $length);
            if ($end <= $start) {
                return '';
            }
            return substr($string, $start, $end - $start);
        });

        $this->addRule('#pos', [], false, function (array $args) {
            return $this->preprocessor->getOutputLength();
        });
        $this->addRule('#out', ['POS', 'TEXT'], false, function (array $args) {
            list($pos, $text) = $args;
            $pos = $this->applyRules($pos, false, '#out/pos');
            $this->preprocessor->insertTextInOutput($pos, $text);
        });

        // State storage
        $this->addRule('#put', ['PATH', 'VALUE'], false, function (array $args) {
            list($path, $value) = $args;
            $parts = explode('/', $path);
            $key = array_pop($parts);
            $partCount = count($parts);

            $table = &$this->tables;
            for ($i = 0; $i < $partCount; ++$i) {
                $part = $parts[$i];
                if (!isset($table[$part])) {
                    $table[$part] = [];
                }
                $table = &$table[$part];
            }
            $table[$key] = $value;

            return '';
        });
        $this->addRule('#get', ['PATH'], false, function (array $args) {
            $arg = $this->applyRules($args[0], false, '#get');
            $parts = explode('/', $arg);
            $key = array_pop($parts);
            $partCount = count($parts);

            $table = &$this->tables;
            for ($i = 0; $i < $partCount; ++$i) {
                $part = $parts[$i];
                if (!isset($table[$part])) {
                    return '';
                }
                $table = &$table[$part];
            }

            return $table[$key];
        });
        $this->addRule('#get', ['PATH', 'DEFAULT_VALUE'], false, function (array $args) {
            list($path, $defaultValue) = $args;

            $parts = explode('/', $path);
            $key = array_pop($parts);
            $partCount = count($parts);

            $table = &$this->tables;
            for ($i = 0; $i < $partCount; ++$i) {
                $part = $parts[$i];
                if (!isset($table[$part])) {
                    return $defaultValue;
                }
                $table = &$table[$part];
            }

            return isset($table[$key]) ? $table[$key] : $defaultValue;
        });


        $this->addRule('#define', ['BODY'], true, 
            function (array $args) {
                $rule = Rule::create($this->preprocessor, $args[0]);
                if ($rule === false) {
                    exit -3;
                } else {
                    if (isset($this->rules[$rule->getSignature()])) {
                        echo "Error: Redefinition of '".$rule->getName()."', ".$this->currentLineStr()."\n";
                        exit -3;
                    }
                    $this->rules[$rule->getSignature()] = $rule;
                }
                return '';
            },
            // Progress function
            function (BuiltinMacroMatch $match, string $replacement) {
                return $match->getPos() + strlen($replacement);
            }
        );
        $this->addRule('#echo', ['TEXT'], true, function (array $args) {
            if ($this->allowSideEffects) {
                echo $args[0]."\n";
            } else {
                print_r($this->allowSideEffectsStack);
            }
            return '';
        });
    }

    public function isSet(Rule $rule) {
        return isset($this->rules[$rule->getSignature()]);
    }

    public function findRulesBySignatureOrName(string $signatureOrName) {
        $found = [];
        foreach ($this->rules as $signature => $rule) {
            if ($signature === $signatureOrName || $rule->getName() === $signatureOrName) {
                $found[] = $rule;
            }
        }
        return $found;
    }

    public function applyRules(string $string, bool $allowSideEffects, string $caller) {
        //echo str_repeat('    ', count($this->allowSideEffectsStack) - 1);
        //echo "applyRules() SE P:".($allowSideEffects ? 'ON' : 'OFF').", M:".($this->allowSideEffects ? 'ON' : 'OFF').", by \"$caller\" {\n";
        $this->allowSideEffectsStack[] = $this->allowSideEffects && $allowSideEffects;
        $this->allowSideEffects = end($this->allowSideEffectsStack);

        $hString = new HistoryString($string);

        $rules = array_values($this->rules);
        foreach ($rules as $rule) {
            $rule->beginSearch();
        }

        // Used to ensure forward progress.
        $prevMatchRule = null;
        $prevMatchPos = -1;
        while (true) {
            // We need to fetch the rules every time, since they can change
            // after each call to $firstMatch->applyTo().
            $rules = array_values($this->rules);
            $firstMatch = null;
            foreach ($rules as $rule) {
                $match = $rule->findMatch($hString);
                if ($match === false) {
                    continue;
                }
                // The same rule mustn't match the same position twice - avoids infinite loops.
                if ($prevMatchRule === $rule && $match->getPos() <= $prevMatchPos) {
                    continue;
                }
                if ($match->getPos() >= $prevMatchPos) {
                    if ($firstMatch === null || $match->getPos() < $firstMatch->getPos()) {
                        $firstMatch = $match;
                    }
                }
            }
            if ($firstMatch === null) {
                break;
            }

            $prevMatchRule = $firstMatch->getRule();
            $prevString = (string) $hString;
            $prevMatchPos = $firstMatch->applyTo($hString);
        }

        foreach ($rules as $rule) {
            $rule->endSearch();
        }

        array_pop($this->allowSideEffectsStack);
        $this->allowSideEffects = end($this->allowSideEffectsStack);

        //echo str_repeat('    ', count($this->allowSideEffectsStack) - 1);
        //echo "} => SE ".($this->allowSideEffects?'ON':'OFF')."\n";
        return (string) $hString;
    }

    public function setRule(Rule $rule) {
        $this->rules[$rule->getSignature()] = $rule;
    }

    public function unsetRule(string $signatureOrName) {
        $rulesToUnset = $this->findRulesBySignatureOrName($signatureOrName);
        if (empty($rulesToUnset)) {
            return false;
        } else {
            foreach ($rulesToUnset as $rule) {
                $signature = $rule->getSignature();
                if ($this->rules[$signature] instanceof BuiltinMacroRule) {
                    // Cannot unset builtin rules.
                    echo "Warning: Trying to unset builtin rule $signature - ignored.\n";
                    continue;
                }
                unset($this->rules[$signature]);
            }
            return true;
        }
    }

    private function addRule(string $name, array $params, bool $allowSideEffects, callable $function, callable $progressFunc = null) {
        $this->setRule(new BuiltinMacroRule($this->preprocessor, $name, $params, $allowSideEffects, function (array $args) use ($function) {
            if (!$this->preprocessor->onEnabledLine()) {
                return '';
            }
            return $function($args);
            }, 
            $progressFunc)
        );
    }
}

class Preprocessor {
    private $output = '';
    private $tempOutput = '';
    private $tables = [];
    private $fileContextStack = [];
    private $ruleContextStack = [];
    private $ruleContexts = [];

    public function __construct() {
        $this->ruleContexts['global'] = new PreprocessorRuleContext($this);
        $this->ruleContextStack[] = $this->ruleContexts['global'];
    }

    public function parseFile(string $file) {
        if (!file_exists($file)) {
            echo "No such file: '$file'\n";
            exit -2;
        }
        
        $this->fileContextStack[] = new PreprocessorFileContext($file);

        // Parse all preprocessor directives, and extract the remaining body text.
        foreach ($this->getLines($file) as $n => list($lineNumber, $line)) {
            end($this->fileContextStack)->setCurrentLine($lineNumber);

            $commentPos = strpos($line, '//');
            if ($commentPos !== false) {
                $line = substr($line, 0, $commentPos);
            }

            $trimmedLine = trim($line);
            if ($trimmedLine == '') {
                $this->tempOutput .= "\n";
                continue;
            }

            $lineIsProcessed = false;
            if ($trimmedLine[0] === '#') {
                $keyword = strTo($trimmedLine, 1, 'strNonAlphaPos');
                $rest = trim(substr($trimmedLine, 1 + strlen($keyword)));
                switch ($keyword) {
                    case 'include': {
                        // Include statement recursively parses the included file, keeping the current rule context.
                        if ($this->onEnabledLine()) {
                            if (strlen($rest) == 0) {
                                echo "Error: Invalid include ".$this->currentLineStr()."\n";
                                exit -3;
                            }
                            if ($rest[0] === '"') {
                                $this->parseFile(trim($rest, '"'));
                                $lineIsProcessed = true;
                            }
                        }
                    } break;
                    case 'define': {
                        // A rule definition.
                        if ($this->onEnabledLine()) {
                            $rule = Rule::create($this, $rest);
                            if ($rule === false) {
                                exit -3;
                            } else {
                                if ($this->isSet($rule)) {
                                    echo "Error: Redefinition of '".$rule->getName()."', @line $lineNumber in $file\n";
                                    exit -3;
                                }
                                end($this->ruleContextStack)->setRule($rule);
                                $lineIsProcessed = true;
                            }
                        }
                    } break;
                    case 'context': {
                        // Rule context switch - switches the context at the top of the stack.
                        if (!isset($this->ruleContexts[$rest])) {
                            $this->ruleContexts[$rest] = new PreprocessorRuleContext($this);
                        }
                        array_pop($this->ruleContextStack);
                        $this->ruleContextStack[] = $this->ruleContexts[$rest];
                    } break;
                    case 'undef': {
                        // Removal of a rule.
                        if ($this->onEnabledLine()) {
                            if (!end($this->ruleContextStack)->unsetRule($rest)) {
                                echo "Warning: '$signatureOrName' is not defined, @line $lineNumber in $file\n";
                            }
                            $lineIsProcessed = true;
                        }
                    } break;
                    case 'ifdef': {
                        // Condition - enables or disables the following lines in the input.
                        $ruleName = substr($rest, 0, strWhitespacePos($rest, 0));
                        $rules = $this->findRulesBySignatureOrName($rest);
                        end($this->fileContextStack)->pushIfValue(!empty($rules));
                        $lineIsProcessed = true;
                    } break;
                    case 'ifndef': {
                        // Condition - enables or disables the following lines in the input.
                        $ruleName = substr($rest, 0, strWhitespacePos($rest, 0));
                        $rules = $this->findRulesBySignatureOrName($rest);
                        end($this->fileContextStack)->pushIfValue(empty($rules));
                        $lineIsProcessed = true;
                    } break;
                    case 'endif': {
                        // Terminates condition.
                        end($this->fileContextStack)->popIfValue();
                        $lineIsProcessed = true;
                    } break;
                }
            }
            if ($lineIsProcessed) {
                $this->output .= trim($this->applyRules($this->tempOutput, true, 'Processed line'));
                $this->tempOutput = '';
            } else if ($this->onEnabledLine()) {
                $this->tempOutput .= $line;
            }
        }

        if (strlen($this->tempOutput) > 0) {
            $this->output .= trim($this->applyRules($this->tempOutput, true, 'Trailing output'));
            $this->tempOutput = '';
        }

        array_pop($this->fileContextStack);
        
        return $this->output;
    }

    public function currentLineStr() {
        return end($this->fileContextStack)->currentLineStr();
    }

    public function getOutputLength() {
        return strlen($this->output);
    }

    public function onEnabledLine() {
        return end($this->fileContextStack)->getCurrentIfValue();
    }

    public function isSet(Rule $rule) {
        return end($this->ruleContextStack)->isSet($rule);
    }

    public function findRulesBySignatureOrName(string $signatureOrName) {
        return end($this->ruleContextStack)->findRulesBySignatureOrName($signatureOrName);
    }

    public function applyRules(string $string, bool $allowSideEffects, string $caller) {
        return end($this->ruleContextStack)->applyRules($string, $allowSideEffects, $caller);
    }

    public function insertTextInOutput(int $pos, string $text) {
        $newOut = substr($this->output, 0, $pos);
        $newOut .= $text;
        $newOut .= substr($this->output, $pos);
        $this->output = $newOut;
    }
    
    function getLines(string $file) {
        $f = fopen($file, 'r');
        try {
            $lineOut = '';
            $lineNumber = 1;
            while ($line = fgets($f)) {
                $lineOut .= $line;
                if (strEndsWith($lineOut, "\\\r\n")) {
                    $lineOut = trim($lineOut, "\\\r\n");
                    ++$lineNumber;
                    continue;
                }
                if (strEndsWith($lineOut, "\\\n")) {
                    $lineOut = trim($lineOut, "\\\n");
                    ++$lineNumber;
                    continue;
                }
                if (strEndsWith($lineOut, "\\\r")) {
                    $lineOut = trim($lineOut, "\\\r");
                    ++$lineNumber;
                    continue;
                }
                yield [$lineNumber, $lineOut];
                $lineOut = '';
                ++$lineNumber;
            }
            if (strlen($lineOut) > 0) {
                yield $lineOut;
            }
        } finally {
            fclose($f);
        }
    }    
} // End class Preprocessor
