<?php

class PreprocessorFileContext {
    private $file = '';
    private $line = 0;

    // Evaluated values of if statements, which are currently surrounding the position
    // we are parsing. E.g. #ifdef.
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
