<?php

function mod($a, $b) {
    if ($b < 0) {
        return -mod(-$a, -$b);
    }
    if ($a < 0) {
        if (is_integer($a) && is_integer($b)) {
            $r = (-$a) % $b;
        } else {
            $r = fmod(-$a, $b);
        }
        if ($r == 0) {
            return 0;
        }
        return $b - $r;
    } else {
        if (is_integer($a) && is_integer($b)) {
            return $a % $b;
        } else {
            return fmod($a, $b);
        }
    }
}