<?php

namespace App\Enums;

/**
 * How a fill fits its last tracks to its budget.
 */
enum FillFit
{
    /** Fill up the budget, the last track may run past it. */
    case Cross;

    /** End as close to the budget as possible (soft fixed time). */
    case Closest;

    /** Reach the budget with the least overrun (hard fixed time, end of hour). */
    case Reach;
}
