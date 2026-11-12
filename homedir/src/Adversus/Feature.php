<?php
declare(strict_types=1);

namespace Whisper\Adversus;

use Whisper\App;
use Whisper\CallPages;
use Whisper\Flags;
use Whisper\Ui;

/**
 * F004 er kun tændt, når login (F001), opkald (F002) og det nye UI (F005_ui) også er det: F004's brugerflade findes kun
 * i det nye UI (ui-ux/F004_design_v1.md §0, Team Lead), og uden den opfører alt sig som før (AK9).
 */
final class Feature
{
    public const FLAG = 'F004_adversus';

    public static function on(): bool
    {
        return Flags::enabled(App::FLAG) && Flags::enabled(CallPages::FLAG) && Flags::enabled(Ui::FLAG)
            && Flags::enabled(self::FLAG);
    }
}
