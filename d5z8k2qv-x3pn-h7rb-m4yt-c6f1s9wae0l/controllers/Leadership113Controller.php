<?php
require_once 'controllers/SessionClassController.php';

/**
 * Leadership 1-1-3. Structure and behaviour are unchanged — everything generic
 * now lives in SessionClassController, which Spiritual Foundations also uses.
 */
class Leadership113Controller extends SessionClassController {
    protected string $programType = 'leadership_113';
    protected string $route       = 'leadership113';
    protected string $label       = 'Leadership 1-1-3';
    // L113 batches vary in length, so eligibility is "no missed sessions" only.
    protected int    $expectedSessions = 0;
    protected string $view        = 'views/leadership_113.php';
}
?>
