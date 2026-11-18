<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\ArraySession;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function test_stores_and_removes_values(): void
    {
        $session = new ArraySession();

        $session->set('user_id', 7);
        self::assertSame(7, $session->get('user_id'));
        self::assertSame('fallback', $session->get('missing', 'fallback'));

        $session->remove('user_id');
        self::assertNull($session->get('user_id'));
    }

    public function test_flash_values_are_pulled_once(): void
    {
        $session = new ArraySession();
        $session->flash('success', 'Saved.');

        self::assertSame('Saved.', $session->pullFlash('success'));
        self::assertNull($session->pullFlash('success'));
    }

    public function test_regenerate_changes_the_session_id(): void
    {
        $session = new ArraySession();
        $before = $session->id();

        $session->regenerate();

        self::assertNotSame($before, $session->id());
    }

    public function test_destroy_clears_data(): void
    {
        $session = new ArraySession();
        $session->set('user_id', 7);

        $session->destroy();

        self::assertNull($session->get('user_id'));
        self::assertNull($session->pullFlash('anything'));
    }
}
