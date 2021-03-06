<?php

declare(strict_types=1);

namespace Yiisoft\Db\Sqlite\Tests;

use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidArgumentException;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Query\Query;
use Yiisoft\Db\TestUtility\TestCommandTrait;

use function str_replace;
use function version_compare;

/**
 * @group sqlite
 */
final class CommandTest extends TestCase
{
    use TestCommandTrait;

    protected string $upsertTestCharCast = 'CAST([[address]] AS VARCHAR(255))';

    public function testAutoQuoting(): void
    {
        $db = $this->getConnection(false);

        $sql = 'SELECT [[id]], [[t.name]] FROM {{customer}} t';

        $command = $db->createCommand($sql);

        $this->assertEquals('SELECT `id`, `t`.`name` FROM `customer` t', $command->getSql());
    }

    public function testForeingKeyException(): void
    {
        $db = $this->getConnection(false);

        $db->createCommand('PRAGMA foreign_keys = ON')->execute();

        $tableMaster = 'departments';
        $tableRelation = 'students';
        $name = 'test_fk_constraint';

        $schema = $db->getSchema();

        if ($schema->getTableSchema($tableRelation) !== null) {
            $db->createCommand()->dropTable($tableRelation)->execute();
        }

        if ($schema->getTableSchema($tableMaster) !== null) {
            $db->createCommand()->dropTable($tableMaster)->execute();
        }

        $db->createCommand()->createTable($tableMaster, [
            'department_id' => 'integer not null primary key autoincrement',
            'department_name' => 'nvarchar(50) null',
        ])->execute();

        $db->createCommand()->createTable($tableRelation, [
            'student_id' => 'integer primary key autoincrement not null',
            'student_name' => 'nvarchar(50) null',
            'department_id' => 'integer not null',
            'dateOfBirth' => 'date null'
        ])->execute();

        $db->createCommand()->addForeignKey(
            $name,
            $tableRelation,
            ['Department_id'],
            $tableMaster,
            ['Department_id']
        )->execute();

        $db->createCommand(
            "INSERT INTO departments VALUES (1, 'IT')"
        )->execute();

        $db->createCommand(
            'INSERT INTO students(student_name, department_id) VALUES ("John", 1);'
        )->execute();

        $expectedMessageError = str_replace(
            "\r\n",
            "\n",
            <<<EOD
SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed
The SQL being executed was: INSERT INTO students(student_name, department_id) VALUES ("Samdark", 5)
EOD
        );

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage($expectedMessageError);

        $db->createCommand(
            'INSERT INTO students(student_name, department_id) VALUES ("Samdark", 5);'
        )->execute();
    }

    public function testMultiStatementSupport(): void
    {
        $db = $this->getConnection(false, true);

        $sql = <<<'SQL'
DROP TABLE IF EXISTS {{T_multistatement}};
CREATE TABLE {{T_multistatement}} (
    [[intcol]] INTEGER,
    [[textcol]] TEXT
);
INSERT INTO {{T_multistatement}} VALUES(41, :val1);
INSERT INTO {{T_multistatement}} VALUES(42, :val2);
SQL;

        $db->createCommand($sql, [
            'val1' => 'foo',
            'val2' => 'bar',
        ])->execute();

        $this->assertSame([
            [
                'intcol' => '41',
                'textcol' => 'foo',
            ],
            [
                'intcol' => '42',
                'textcol' => 'bar',
            ],
        ], $db->createCommand('SELECT * FROM {{T_multistatement}}')->queryAll());

        $sql = <<<'SQL'
UPDATE {{T_multistatement}} SET [[intcol]] = :newInt WHERE [[textcol]] = :val1;
DELETE FROM {{T_multistatement}} WHERE [[textcol]] = :val2;
SELECT * FROM {{T_multistatement}}
SQL;

        $this->assertSame([
            [
                'intcol' => '410',
                'textcol' => 'foo',
            ],
        ], $db->createCommand($sql, [
            'newInt' => 410,
            'val1' => 'foo',
            'val2' => 'bar',
        ])->queryAll());
    }

    public function batchInsertSqlProvider(): array
    {
        $parent = $this->batchInsertSqlProviderTrait();

        /* Produces SQL syntax error: General error: 1 near ".": syntax error */
        unset($parent['wrongBehavior']);

        return $parent;
    }

    /**
     * Make sure that `{{something}}` in values will not be encoded.
     *
     * @dataProvider batchInsertSqlProvider
     *
     * @param string $table
     * @param array $columns
     * @param array $values
     * @param string $expected
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidConfigException
     * @throws NotSupportedException
     *
     * {@see https://github.com/yiisoft/yii2/issues/11242}
     */
    public function testBatchInsertSQL(
        string $table,
        array $columns,
        array $values,
        string $expected,
        array $expectedParams = []
    ): void {
        $db = $this->getConnection(true);

        $command = $db->createCommand();

        $command->batchInsert($table, $columns, $values);

        $command->prepare(false);

        $this->assertSame($expected, $command->getSql());
        $this->assertSame($expectedParams, $command->getParams());
    }

    /**
     * Test whether param binding works in other places than WHERE.
     *
     * @dataProvider bindParamsNonWhereProviderTrait
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     *
     * @param string $sql
     */
    public function testBindParamsNonWhere(string $sql): void
    {
        $db = $this->getConnection();

        $db->createCommand()->insert(
            'customer',
            [
                'name' => 'testParams',
                'email' => 'testParams@example.com',
                'address' => '1'
            ]
        )->execute();

        $params = [
            ':email' => 'testParams@example.com',
            ':len'   => 5,
        ];

        $command = $db->createCommand($sql, $params);

        $this->assertEquals('Params', $command->queryScalar());
    }

    /**
     * Test command getRawSql.
     *
     * @dataProvider getRawSqlProviderTrait
     *
     * @param string $sql
     * @param array $params
     * @param string $expectedRawSql
     *
     * @throws Exception
     * @throws InvalidConfigException
     * @throws NotSupportedException
     *
     * {@see https://github.com/yiisoft/yii2/issues/8592}
     */
    public function testGetRawSql(string $sql, array $params, string $expectedRawSql): void
    {
        $db = $this->getConnection();

        $command = $db->createCommand($sql, $params);

        $this->assertEquals($expectedRawSql, $command->getRawSql());
    }

    /**
     * Test INSERT INTO ... SELECT SQL statement with wrong query object.
     *
     * @dataProvider invalidSelectColumnsProviderTrait
     *
     * @param mixed $invalidSelectColumns
     *
     * @throws Exception
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testInsertSelectFailed($invalidSelectColumns): void
    {
        $db = $this->getConnection();

        $query = new Query($db);

        $query->select($invalidSelectColumns)->from('{{customer}}');

        $command = $db->createCommand();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected select query object with enumerated (named) parameters');

        $command->insert(
            '{{customer}}',
            $query
        )->execute();
    }

    /**
     * @dataProvider upsertProviderTrait
     *
     * @param array $firstData
     * @param array $secondData
     *
     * @throws Exception
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testUpsert(array $firstData, array $secondData)
    {
        if (version_compare($this->getConnection()->getServerVersion(), '3.8.3', '<')) {
            $this->markTestSkipped('SQLite < 3.8.3 does not support "WITH" keyword.');

            return;
        }

        $db = $this->getConnection(true);

        $this->assertEquals(0, $db->createCommand('SELECT COUNT(*) FROM {{T_upsert}}')->queryScalar());

        $this->performAndCompareUpsertResult($db, $firstData);

        $this->assertEquals(1, $db->createCommand('SELECT COUNT(*) FROM {{T_upsert}}')->queryScalar());

        $this->performAndCompareUpsertResult($db, $secondData);
    }
}
