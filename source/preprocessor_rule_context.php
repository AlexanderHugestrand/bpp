<?php

require_once __DIR__.'/preprocessor.php';

class PreprocessorRuleContext {
    private $preprocessor;
    private $rules = [];
    private $tables = [];
    private $tablesBackup = [];
    private $allowSideEffectsStack = [ true ];
    private $allowSideEffects = true;

    public function __construct(Preprocessor $preprocessor) {
        $this->preprocessor = $preprocessor;
        
        // Arithmetic operations.
        $this->addRule('#add', ['A', 'B'], false, function (array $args, bool $se) { return $args[0] + $args[1]; });
        $this->addRule('#sub', ['A', 'B'], false, function (array $args, bool $se) { return $args[0] - $args[1]; });
        $this->addRule('#mul', ['A', 'B'], false, function (array $args, bool $se) { return $args[0] * $args[1]; });
        $this->addRule('#div', ['A', 'B'], false, function (array $args, bool $se) { return $args[0] / $args[1]; });
        $this->addRule('#idiv', ['A', 'B'], false, function (array $args, bool $se) { return (int) ($args[0] / $args[1]); });
        $this->addRule('#mod', ['A', 'B'], false, function (array $args, bool $se) { return $this->mod($args[0], $args[1]); });

        // String operations.
        $this->addRule('#substr', ['STR', 'START', 'END'], false, function (array $args, bool $se) {
            $string = $args[0];
            $length = strlen($string);
            $start = mod($args[1], $length);
            $end = mod($args[2], $length);
            if ($end <= $start) {
                return '';
            }
            return substr($string, $start, $end - $start);
        });

        $this->addRule('#pos', [], false, function (array $args, bool $se) {
            return $this->preprocessor->getOutputLength();
        });
        $this->addRule('#out', ['POS', 'TEXT'], true, function (array $args, bool $se) {
            if ($this->allowSideEffects) {
                list($pos, $text) = $args;
                $pos = $this->applyRules($pos, false, '#out/pos');
                $this->preprocessor->insertTextInOutput($pos, $text);
            }
        });

        // State storage
        $this->addRule('#put', ['PATH', 'VALUE'], true, function (array $args, bool $se) {
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

            if ($this->allowSideEffects) {
                return '';
            } else {
                // Evaluate later.
                return "#put($path, $value)";
            }
        });
        $this->addRule('#get', ['PATH'], false, function (array $args, bool $se) {
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
        $this->addRule('#get', ['PATH', 'DEFAULT_VALUE'], false, function (array $args, bool $se) {
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


        $this->addRule('#define', ['BODY'], false, function (array $args, bool $se) {
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
        $this->addRule('#echo', ['TEXT'], true, function (array $args, bool $se) {
            if ($this->allowSideEffects) {
                echo $args[0]."\n";
                return '';
            } else {
                // Evaluate later.
                return '#echo('.$args[0].')';
            }
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
        $didAllow = $this->allowSideEffects;
        $this->allowSideEffects = end($this->allowSideEffectsStack);

        if ($didAllow && !$this->allowSideEffects) {
            // Store the current state, and let any side effects happen to $this->tables.
            $this->tablesBackup = $this->tables;
        } else if (!$didAllow && $this->allowSideEffects) {
            // Restore the backup.
            $this->tables = $this->tablesBackup;
        }

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
            $prevMatchPos = $firstMatch->applyTo($hString, $this->allowSideEffects);
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

    private function addRule(string $name, array $params, bool $hasSideEffects, callable $function, callable $progressFunc = null) {
        $this->setRule(new BuiltinMacroRule($this->preprocessor, $name, $params, $hasSideEffects, 
            function (array $args, bool $allowSideEffects) use ($function) {
                if (!$this->preprocessor->onEnabledLine()) {
                    return '';
                }
                return $function($args, $allowSideEffects);
            }, 
            $progressFunc)
        );
    }
}
