<?php

namespace Tests\Unit;

use App\Services\ErrorAlert;
use PHPUnit\Framework\TestCase;

/** What an error email may carry: the error, never the guests' data bound into it. */
class ErrorAlertRedactTest extends TestCase
{
    public function test_a_database_error_loses_its_statement_and_bound_values(): void
    {
        $message = 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry (Connection: mariadb, SQL: insert into customers (phone, name) values (01012345678, Hany))';

        $this->assertSame('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', ErrorAlert::redact($message));
    }

    public function test_emails_tokens_and_long_numbers_are_masked(): void
    {
        $this->assertSame('cURL error 28 for ***@*** token *** phone ***',
            ErrorAlert::redact('cURL error 28 for hany@nile.test token EAAGm0PX4ZCpsBAJZCZBqZBZCZBZAZB1234567890abcdefgh phone 201012345678'));
    }
}
