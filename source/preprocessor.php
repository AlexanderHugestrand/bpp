<?php

require_once __DIR__.'/string_functions.php';
require_once __DIR__.'/rule.php';
require_once __DIR__.'/math.php';
require_once __DIR__.'/preprocessor_file_context.php';
require_once __DIR__.'/preprocessor_rule_context.php';

class Preprocessor {
    private $output = '';
    private $tempOutput = '';
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
                $this->output .= trim(strUnescape($this->applyRules($this->tempOutput, true, 'Processed line')));
                $this->tempOutput = '';
            } else if ($this->onEnabledLine()) {
                $this->tempOutput .= $line;
            }
        }

        if (strlen($this->tempOutput) > 0) {
            $this->output .= trim(strUnescape($this->applyRules($this->tempOutput, true, 'Trailing output')));
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
        $newOut .= strUnescape($text);
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
