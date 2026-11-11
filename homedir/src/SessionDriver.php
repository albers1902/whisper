<?php
declare(strict_types=1);

namespace Whisper;

/** Det, Session ikke kan gøre med et array alene: id, nyt id og sletning. Web: PHP's sessioner; tests: et array. */
interface SessionDriver
{
    public function id(): string;

    public function regenerate(): void;

    public function destroy(): void;
}
