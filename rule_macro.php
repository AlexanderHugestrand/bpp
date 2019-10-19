<?php

require_once __DIR__.'/rule.php';

class MacroRule extends Rule {
    private $name;
    private $signature;
    private $parameters = [];
    private $parameterConditions = [];
    private $body;

    public function __construct(Preprocessor $preprocessor, string $name, array $parameters, string $body) {
        parent::__construct($preprocessor);
        $this->name = $name;
        $this->signature = strNormalizeSpaces($name.'('.implode(', ', $parameters).')');

        $paramIndex = [];
        $conditionMap = [];
        $i = 0;
        foreach ($parameters as $param) {
            $parts = preg_split('/\\s+/', $param);
            $this->parameters[] = $preprocessor->applyRules($parts[0], false, 'Param of macro '.$this->signature);
            $paramIndex[$parts[0]] = $i;
            ++$i;
            if (count($parts) < 3) {
                continue;
            }
            $operator = $parts[1];
            $rOperand = $preprocessor->applyRules($parts[2], false, 'rOp#1 of macro '.$this->signature);
            $conditionMap[$parts[0]] = [$operator, $rOperand];
        }

        foreach ($this->parameters as $pName) {
            if (!isset($conditionMap[$pName])) {
                $this->parameterConditions[] = function ($arg) { return true; };
                continue;
            }

            list($operator, $rOperand) = $conditionMap[$pName];

            // Is the right operand a reference to another argument?
            if (isset($paramIndex[$rOperand])) {
                $pIndex = $paramIndex[$rOperand];
                switch ($operator) {
                    case '==': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($pIndex) { return $currentArg === $allArgs[$pIndex]; }; break;
                    case '!=': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($pIndex) { return $currentArg !== $allArgs[$pIndex]; }; break;
                    case  '<': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($pIndex) { return $currentArg < $allArgs[$pIndex];}; break;
                    case  '>': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($pIndex) { return $currentArg > $allArgs[$pIndex];}; break;
                    case '<=': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($pIndex) { return $currentArg <= $allArgs[$pIndex];}; break;
                    case '>=': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($pIndex) { return $currentArg >= $allArgs[$pIndex];}; break;
                    default: {
                        echo "Invalid operator '$operator'";
                        exit -3;
                    } break;
                }    
            } else {
                $value = $preprocessor->applyRules($rOperand, false, 'rOp#2 of macro '.$this->signature);
                switch ($operator) {
                    case '==': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($value) { return $currentArg === $value; }; break;
                    case '!=': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($value) { return $currentArg !== $value; }; break;
                    case  '<': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($value) { return $currentArg < $value;}; break;
                    case  '>': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($value) { return $currentArg > $value;}; break;
                    case '<=': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($value) { return $currentArg <= $value;}; break;
                    case '>=': $this->parameterConditions[] = function ($currentArg, $allArgs) use ($value) { return $currentArg >= $value;}; break;
                    default: {
                        echo "Invalid operator '$operator'";
                        exit -3;
                    } break;
                }    
            }
        }

        $this->body = $body;
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

    public function getBody() {
        return $this->body;
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

                for ($i = 0; $i < count($args); ++$i) {
                    $args[$i] = $this->applyRules($args[$i], false, 'Arg of macro '.$this->getSignature());
                }

                // Check all conditions.
                $allTrue = true;
                for ($i = 0; $i < count($args) && $i < count($this->parameters); ++$i) {
                    if ($this->parameters[$i] === '...') {
                        continue;
                    }
                    if ($this->parameterConditions[$i]($args[$i], $args) !== true) {
                        $allTrue = false;
                        break;
                    }
                }

                if ($allTrue) {
                    if (count($args) === count($this->parameters)) {
                        return new MacroMatch($this, $pos, $endPos + 1 - $pos, $args);
                    } else if (count($this->parameters) > 0 && count($args) >= count($this->parameters) && end($this->parameters) === '...') {
                        return new MacroMatch($this, $pos, $endPos + 1 - $pos, $args);
                    }    
                }

                $pos = $endPos + 1;
            } else {
                $pos = $argPos;
            }
        }
    }
}

class MacroMatch extends RuleMatch {
    private $arguments;
    private $replacement;

    public function __construct(MacroRule $rule, int $pos, int $length, array $arguments) {
        parent::__construct($rule, $pos, $length);
        $this->arguments = $arguments;

        $body = $this->getRule()->getBody();
        $namedArgs = $this->getNamedArguments();

        $namedArgs['__C_ARGS__'] = count($arguments);
        if (isset($namedArgs['...'])) {
            $namedArgs['__VA_ARGS__'] = implode(', ', $namedArgs['...']);
            unset($namedArgs['...']);
        }

        foreach ($namedArgs as $find => $replace) {
            $pos = 0;
            $out = '';
            $regex = '/(^|[^a-zA-Z0-9_])('.$find.')($|[^a-zA-Z0-9_])/';
            $matches = [];
            if (preg_match_all($regex, $body, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[2] as $match) {
                    $out .= substr($body, $pos, $match[1] - $pos);
                    $out .= $replace;
                    $pos = $match[1] + strlen($find);
                }
            }
            $out .= substr($body, $pos);
            $body = $out;
        }

        $this->replacement = trim($body);
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

    public function applyTo(HistoryString &$hString) {
        $hString->replace($this->getPos(), $this->getEnd(), $this->replacement, $this);
        return $this->getPos();
    }
}
