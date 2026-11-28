<?php
/**
 * A scripted stand-in for $wpdb, so database code can be tested without a database.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Support;

/**
 * Records every statement. Answers come from rules: the first rule whose pattern matches the SQL wins.
 * A rule with $error set makes that statement fail the way wpdb does (false or null, last_error filled).
 */
class FakeWpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Database name.
	 *
	 * @var string
	 */
	public string $dbname = 'site_one';

	/**
	 * Like wpdb's setting: how many times it tries to reconnect (and then run a statement again).
	 *
	 * @var int
	 */
	public int $reconnect_retries = 5;

	/**
	 * Error text of the last statement, empty when it worked.
	 *
	 * @var string
	 */
	public string $last_error = '';

	/**
	 * Id of the last insert.
	 *
	 * @var int
	 */
	public int $insert_id = 0;

	/**
	 * Every statement, in order.
	 *
	 * @var list<string>
	 */
	public array $log = array();

	/**
	 * Rules: pattern, answer and whether the statement fails.
	 *
	 * @var list<array{0: string, 1: mixed, 2: bool}>
	 */
	private array $rules = array();

	/**
	 * Adds a rule. Rules added later lose to earlier ones, so add the specific ones first.
	 *
	 * @param string $pattern Regex matched against the SQL.
	 * @param mixed  $answer  What the statement returns.
	 * @param bool   $error   Whether it fails.
	 */
	public function when( string $pattern, mixed $answer = null, bool $error = false ): self {
		$this->rules[] = array( $pattern, $answer, $error );
		return $this;
	}

	/**
	 * Answers each CONNECTION_ID() query with the next id in the list (the last one repeats).
	 *
	 * @param array<int, string> $ids Connection ids, in the order they are asked for.
	 */
	public function connection_ids( array $ids ): self {
		$asked = 0;
		return $this->when(
			'/CONNECTION_ID/',
			static function () use ( $ids, &$asked ): string {
				$id = $ids[ min( $asked, count( $ids ) - 1 ) ];
				++$asked;
				return $id;
			}
		);
	}

	/**
	 * Makes every plugin table report InnoDB.
	 *
	 * @param array<int, string> $tables Short table names.
	 */
	public function all_innodb( array $tables = array( 'match_lineups', 'audit_log' ) ): self {
		$rows = array();
		foreach ( $tables as $short ) {
			$rows[] = array(
				'name'   => $this->prefix . 'cgc_' . $short,
				'engine' => 'InnoDB',
			);
		}
		return $this->when( '/information_schema/', $rows );
	}

	/**
	 * Index of the first logged statement matching a pattern, or null.
	 *
	 * @param string $pattern Regex.
	 */
	public function position( string $pattern ): ?int {
		foreach ( $this->log as $i => $sql ) {
			if ( 1 === preg_match( $pattern, $sql ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Statements matching a pattern.
	 *
	 * @param string $pattern Regex.
	 * @return list<string>
	 */
	public function matching( string $pattern ): array {
		return array_values( array_filter( $this->log, static fn( string $sql ): bool => 1 === preg_match( $pattern, $sql ) ) );
	}

	/**
	 * Logs a statement and finds its answer.
	 *
	 * @param string $sql      SQL or a description of the call.
	 * @param mixed  $fallback Answer when no rule matches.
	 * @param mixed  $failure  Answer when the rule says it fails.
	 */
	private function run( string $sql, mixed $fallback, mixed $failure ): mixed {
		$this->log[]      = $sql;
		$this->last_error = '';
		foreach ( $this->rules as $rule ) {
			if ( 1 === preg_match( $rule[0], $sql ) ) {
				if ( $rule[2] ) {
					$this->last_error = 'simulated failure';
					return $failure;
				}
				return $rule[1] instanceof \Closure ? ( $rule[1] )( $sql ) : $rule[1];
			}
		}
		if ( str_contains( $sql, 'CONNECTION_ID' ) ) {
			return '42'; // The same connection throughout, unless a test says otherwise.
		}
		return $fallback;
	}

	/**
	 * Like wpdb::prepare().
	 *
	 * @param string $query Query with %d and %s.
	 * @param mixed  ...$args Values, or one array of values.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}
		$i = 0;
		return (string) preg_replace_callback(
			'/%[dsi]/',
			static function ( array $m ) use ( &$i, $args ): string {
				$value = $args[ $i++ ] ?? '';
				return match ( $m[0] ) {
					'%d' => (string) (int) $value,
					'%i' => '`' . str_replace( '`', '', (string) $value ) . '`',
					default => "'" . addslashes( (string) $value ) . "'",
				};
			},
			$query
		);
	}

	/**
	 * Like wpdb::esc_like().
	 *
	 * @param string $text Text.
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Like wpdb::suppress_errors().
	 *
	 * @param bool $suppress Whether to suppress.
	 */
	public function suppress_errors( bool $suppress = true ): bool {
		unset( $suppress );
		return false;
	}

	/**
	 * Like wpdb::query().
	 *
	 * @param string $sql SQL.
	 */
	public function query( string $sql ): int|bool {
		return $this->run( $sql, 1, false ); // @phpstan-ignore-line
	}

	/**
	 * Like wpdb::get_var().
	 *
	 * @param string $sql SQL.
	 */
	public function get_var( string $sql ): mixed {
		return $this->run( $sql, null, null );
	}

	/**
	 * Like wpdb::get_row().
	 *
	 * @param string $sql  SQL.
	 * @param string $mode Output type.
	 */
	public function get_row( string $sql, string $mode = 'OBJECT' ): mixed {
		unset( $mode );
		return $this->run( $sql, null, null );
	}

	/**
	 * Like wpdb::get_results().
	 *
	 * @param string $sql  SQL.
	 * @param string $mode Output type.
	 */
	public function get_results( string $sql, string $mode = 'OBJECT' ): mixed {
		unset( $mode );
		return $this->run( $sql, array(), null );
	}

	/**
	 * Like wpdb::insert().
	 *
	 * @param string               $table Table.
	 * @param array<string, mixed> $data  Row.
	 */
	public function insert( string $table, array $data ): int|false {
		unset( $data );
		$answer = $this->run( 'INSERT INTO ' . $table, 1, false );
		if ( false !== $answer ) {
			++$this->insert_id;
		}
		return $answer; // @phpstan-ignore-line
	}

	/**
	 * Like wpdb::update().
	 *
	 * @param string               $table Table.
	 * @param array<string, mixed> $data  Values.
	 * @param array<string, mixed> $where Match.
	 */
	public function update( string $table, array $data, array $where ): int|false {
		unset( $data, $where );
		return $this->run( 'UPDATE ' . $table, 1, false ); // @phpstan-ignore-line
	}

	/**
	 * Like wpdb::delete().
	 *
	 * @param string               $table Table.
	 * @param array<string, mixed> $where Match.
	 */
	public function delete( string $table, array $where ): int|false {
		return $this->run( 'DELETE FROM ' . $table . ' WHERE ' . http_build_query( $where ), 1, false ); // @phpstan-ignore-line
	}
}
