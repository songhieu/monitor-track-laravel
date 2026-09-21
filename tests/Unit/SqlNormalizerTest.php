<?php

namespace MonitorTrack\Tests\Unit;

use MonitorTrack\Support\SqlNormalizer;
use PHPUnit\Framework\TestCase;

class SqlNormalizerTest extends TestCase
{
    public function test_in_lists_collapse(): void
    {
        $this->assertSame(
            'select * from "orders" where "customer_id" in (?)',
            SqlNormalizer::normalize('select * from "orders" where "customer_id" in (?, ?, ?,?)'),
        );
        $this->assertSame(
            'select * from `t` where `a` IN (?) and `b` not in (?)',
            SqlNormalizer::normalize("select * from `t` where `a` IN ( 1, 2 ,3 ) and `b` not in ('x', 'y')"),
        );
        $this->assertSame(
            'select * from t where id in (?)',
            SqlNormalizer::normalize('select * from t where id in (?)'),
        );
    }

    public function test_literals_become_placeholders(): void
    {
        $this->assertSame(
            'update users set name = ?, note = ?, age = ?, score = -?, hex = ?, ratio = ?',
            SqlNormalizer::normalize("update users set name = 'O''Brien', note = 'it\\'s', age = 42, score = -1.5e3, hex = 0x1F, ratio = .5"),
        );
        $this->assertSame(
            'select * from `users` where `users`.`id` = ? limit ?',
            SqlNormalizer::normalize('select * from `users` where `users`.`id` = ? limit 1'),
        );
        $this->assertSame(
            'select * from t where s = ?',
            SqlNormalizer::normalize("select * from t where s = 'secret that was cut off"),
        );
    }

    public function test_identifiers_keep_their_digits(): void
    {
        $this->assertSame(
            'select "t1"."col2", `order_2024`.`2fa`, $1 from "t1" where x2 = ?',
            SqlNormalizer::normalize('select "t1"."col2", `order_2024`.`2fa`, $1 from "t1" where x2 = 7'),
        );
    }

    public function test_double_quotes_are_strings_on_mysql_only(): void
    {
        $this->assertSame('select * from t where a = ?', SqlNormalizer::normalize('select * from t where a = "secret \\" value"', true));
        $this->assertSame('select * from t where "a" = ?', SqlNormalizer::normalize('select * from t where "a" = \'x\''));
    }

    public function test_whitespace_collapses_and_length_is_capped(): void
    {
        $this->assertSame('select * from t where a = ?', SqlNormalizer::normalize("  select   *\n\tfrom t\r\n where a = 'multi\nline'  "));

        $long = 'select '.implode(', ', array_map(fn ($i) => "col_{$i}", range(1, 1000))).' from t';
        $normalized = SqlNormalizer::normalize($long);
        $this->assertLessThanOrEqual(SqlNormalizer::MAX_LENGTH, strlen($normalized));
        $this->assertStringEndsWith('…', $normalized);

        $huge = 'select * from t where id in ('.implode(', ', range(1, 50000)).") and s = 'x'";
        $this->assertSame('select * from t where id in (?)', SqlNormalizer::normalize($huge));
    }
}
