<?php

require_once __DIR__.'/math.php';

// True/false if $haystack starts with $needle.
function strStartsWith($haystack, $needle) {
     return (substr($haystack, 0, strlen($needle)) === $needle);
}

// True/false if $haystack ends with $needle.
function strEndsWith($haystack, $needle) {
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }
    return (substr($haystack, -$length) === $needle);
}

// Returns position of first whitespace character, beginning at 'start'.
function strWhitespacePos(string $str, int $start) {
    $len = strlen($str);
    for ($i = $start; $i < $len; ++$i) {
        if (ctype_space($str[$i])) {
            return $i;
        }
    }
    return $len;
}

// Returns position of first character NOT a WHITESPACE character, beginning at 'start'.
function strNonWhitespacePos(string $str, int $start) {
    $len = strlen($str);
    for ($i = $start; $i < $len; ++$i) {
        if (!ctype_space($str[$i])) {
            return $i;
        }
    }
    return $len;
}

// Returns position of first character NOT a LETTER, beginning at 'start'.
function strNonAlphaPos(string $str, int $start) {
    $len = strlen($str);
    for ($i = $start; $i < $len; ++$i) {
        if (!ctype_alpha($str[$i])) {
            return $i;
        }
    }
    return $len;
}

// Returns position of first character NOT a DIGIT, beginning at 'start'.
function strNonNumericalPos(string $str, int $start) {
    $len = strlen($str);
    for ($i = $start; $i < $len; ++$i) {
        if (!ctype_digit($str[$i])) {
            return $i;
        }
    }
    return $len;
}

// Returns position of first character NOT an IDENTIFIER, beginning at 'start'.
// Valid characters in identifiers are letters, digits and underscore.
function strNonIdentifierPos(string $str, int $start) {
    $len = strlen($str);
    for ($i = $start; $i < $len; ++$i) {
        if (!ctype_alnum($str[$i]) && $str[$i] != '_') {
            return $i;
        }
    }
    return $len;
}

// Returns position of first occurence of $char, beginning at $start,
// but ignores occurences of $char that are preceded by a backslash (escaped characters).
function strCharPos(string $str, int $start, string $char) {
    $len = strlen($str);
    for ($i = $start; $i < $len; ++$i) {
        if ($str[$i] === "\\") {
            ++$i;
            continue;
        }
        if ($str[$i] === $char) {
            return $i;
        }
    }
    return -1;
}

// Returns an array of positions (sorted) of all occurences of $needle.
function strFindAll(string $haystack, string $needle) {
    $out = [];
    $pos = 0;
    while (true) {
        $needlePos = strpos($haystack, $needle, $pos);
        if ($needlePos === false) {
            return $out;
        }
        // Don't match if the first character in the needle is escaped.
        if ($needlePos > 0 && substr($haystack, $needlePos - 1, 1) === "\\") {
            continue;
        }
        $out[] = $needlePos;
        $pos = $needlePos + strlen($needle);
    }
}

// Returns an array with all occurences (sorted by position) of all the needles.
// Each entry in the array is an array in itself:
// ['pos' => int, 'needle' => string]
function strMultiFindAll(string $haystack, array $needles) {
    // First find all occurences of each needle - the result will be unsorted.
    $findPositions = [];
    foreach ($needles as $needle) {
        $findPositions[] = [
            'positions' => strFindAll($haystack, $needle),
            'needle' => $needle,
            'next' => 0, // Next index into the 'positions' array.
        ];
    }

    // Sort the output by position.
    $sortedOutput = [];
    while (true) {
        $minPos = -1;
        $minPosI = -1;
        
        for ($i = 0; $i < count($findPositions); ++$i) {
            $positions = $findPositions[$i]['positions'];
            $next = $findPositions[$i]['next'];
            if ($next >= count($positions)) {
                continue;
            }
            if ($minPos < 0 || $positions[$next] < $minPos) {
                $minPos = $positions[$next];
                $minPosI = $i;
            }
        }
        
        if ($minPos < 0) {
            // Here's the return statement! (I tend to look at the bottom of the function for it).
            return $sortedOutput;
        }

        $sortedOutput[] = [
            'pos' => $minPos,
            'needle' => $findPositions[$minPosI]['needle']
        ];
        $findPositions[$minPosI]['next']++;
    }
}

// Returns substring from $start to the first character accepted by $charPredicate.
function strTo(string $str, int $start, callable $charPredicate) {
    $pos = $charPredicate($str, $start);
    return substr($str, $start, $pos - $start);
}

// Returns substring from the first character accepted by $charPredicate, beginning at $start.
function strAfter(string $str, int $start, callable $charPredicate) {
    $pos = $charPredicate($str, $start);
    return substr($str, $pos + 1);
}

function strToChar(string $str, int $start, string $char, $defaultReturnValue) {
    $len = strlen($str);
    for ($i = $start; $i < $len; ++$i) {
        if ($str[$i] === '\\') {
            ++$i;
            continue;
        }
        if ($str[$i] === $char) {
            return substr($str, $start, $i - $start);
        }
    }
    return $defaultReturnValue;
}

function strAfterChar(string $str, int $start, string $char) {
    $len = strlen($str);
    for ($i = $start; $i < $len; ++$i) {
        if ($str[$i] === '\\') {
            ++$i;
            continue;
        }
        if ($str[$i] === $char) {
            return substr($str, $i + 1);
        }
    }
    return $str;
}

// Like substr(), but with 'start' and 'end' instead of 'start' and 'length'.
function strCut(string $string, int $start, int $end) {
    $length = strlen($string);
    $start = mod($start, $length);
    $end = mod($end, $length);
    if ($start <= $end) {
        return substr($string, $start, $end - $start);
    }
    // Is it dumb to add an extra feature like this? If $end < $start (which we could consider an error)
    // return the substring reversed.
    $s = substr($string, $end, $start - $end);
    return strrev($s);
}

// Returns the end position of a 'block', i.e. something like a scope or parantheses.
function strBlockEndPos(string $str, int $start, array $openingStrings, array $closingStrings) {
    $needles = array_merge($openingStrings, $closingStrings);
    $matches = strMultiFindAll(substr($str, $start), $needles);

    $stack = [];
    foreach ($matches as $match) {
        $matchPos = $match['pos'];
        $needle = $match['needle'];

        if (in_array($needle, $openingStrings)) {
            $stack[] = $needle;
        } else if (in_array($needle, $closingStrings)) {
            $key = array_keys($closingStrings, $needle)[0];
            $openChar = $openingStrings[$key];
            if (end($stack) !== $openChar) {
                echo "Mismatched '$openChar' with '$needle'.\n";
                exit -1;
            }
            array_pop($stack);
            if (empty($stack)) {
                return $matchPos + $start;
            }
        }
    }
    return -1;
}

// Explodes the argument list to a function, and doesn't match escaped delimiters.
function strArgExplode(string $delimiter, string $string, array $openingStrings, array $closingStrings) {
    $needles = [$delimiter];
    $needles = array_merge($needles, $openingStrings, $closingStrings);
    $matches = strMultiFindAll($string, $needles);

    $parts = [];
    $stack = [];
    $partStart = 0;
    foreach ($matches as $match) {
        $matchPos = $match['pos'];
        $needle = $match['needle'];
        if ($needle === $delimiter && count($stack) == 0) {
            $parts[] = trim(substr($string, $partStart, $matchPos - $partStart));
            $partStart = $matchPos + strlen($delimiter);
        } else if (in_array($needle, $openingStrings)) {
            $stack[] = $needle;
        } else if (in_array($needle, $closingStrings)) {
            $key = array_keys($closingStrings, $needle)[0];
            $openChar = $openingStrings[$key];
            if (end($stack) !== $openChar) {
                echo "Mismatched '$openChar' with '$needle'.\n";
                exit -1;
            }
            array_pop($stack);
        }
    }

    if ($partStart < strlen($string)) {
        $parts[] = trim(substr($string, $partStart, strlen($string) - $partStart));
    }

    return $parts;
}

function strReplace(string $subject, int $from, int $to, string $replacement) {
    $out = substr($subject, 0, $from);
    $out .= $replacement;
    $out .= substr($subject, $to);
    return $out;
}

function strNormalizeSpaces(string $string) {
    $string = preg_replace('/\r/', "\n", $string);
    $string = preg_replace('/\h+/', ' ', $string);
    $string = preg_replace('/\n\h\n/', "\n", $string);
    $string = preg_replace('/\n\h/', "\n", $string);
    return trim(preg_replace('/\n+/', "\n", $string));
}

function strUnescape(string $text) {
    $textLength = strlen($text);
    $textOut = '';
    for ($i = 0; $i < $textLength; ++$i) {
        if ($text[$i] === '\\') {
            ++$i;
            switch ($text[$i]) {
                case "r": $textOut .= "\r"; break;
                case "n": $textOut .= "\n"; break;
                case "t": $textOut .= "\t"; break;
                default: $textOut .= $text[$i]; break;
            }
        } else {
            $textOut .= $text[$i];
        }
    }
    return $textOut;
}