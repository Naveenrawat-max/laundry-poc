<?php
/** Small functions used everywhere: rows as arrays, the date helpers, running a .sql file, and the one error shape. */
declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** All rows of a query, each as an array. Values are bound to the "?" marks, never pasted into the SQL. */
function rows(string $sql, array $params = []): array
{
    return array_map(fn (object $row): array => (array) $row, DB::select($sql, $params));
}

/** The first row of a query as an array, or null when there is none. */
function row(string $sql, array $params = []): ?array
{
    $row = DB::selectOne($sql, $params);
    return $row === null ? null : (array) $row;
}

/** "?,?,?" for an IN (...) list with one mark per element. */
function marks(array $xs): string
{
    return implode(',', array_fill(0, count($xs), '?'));
}

/** Today as a calendar day in the Netherlands. DATE columns hold these, DATETIME columns hold UTC. */
function today_nl(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
}

function days_after(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify(sprintf('%+d days', $days))->format('Y-m-d');
}

/** Runs every statement of a .sql file. The files have no ";" at a line end inside a statement. */
function run_sql_file(string $path): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($path));
    foreach (preg_split('/;[ \t]*(?:\r?\n|$)/', $sql) as $statement) {
        if (trim($statement) !== '') {
            DB::unprepared($statement);
        }
    }
}

/** Stops the request with the API's one error shape. Inside DB::transaction() it also rolls the transaction back. */
function fail(int $status, string $code, string $message): never
{
    throw new HttpResponseException(response()->json(['error' => ['code' => $code, 'message' => $message]], $status));
}

/** Any exception as the API's one error shape. bootstrap/app.php calls this for every failed API request. */
function api_error(Throwable $e): Response
{
    if ($e instanceof HttpResponseException) {
        return $e->getResponse();   // made by fail()
    }
    $dbError = $e instanceof QueryException ? ($e->errorInfo[1] ?? 0) : 0;
    $http = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 0;
    [$status, $code, $message] = match (true) {
        $e instanceof ValidationException => [422, 'invalid_input', $e->validator->errors()->first()],
        $e instanceof AuthenticationException => [401, 'not_logged_in', 'Log in first.'],
        // The database is the last line of defence. Its refusals become the API's error codes.
        $dbError === 1062 => [409, 'duplicate', 'That value is already in use.'],
        $dbError === 1452 => [422, 'invalid_reference', 'A referenced record does not exist.'],
        $dbError === 3819, $dbError === 4025 => [422, 'rule_violation', 'The database refused an impossible state.'],
        $http === 404 => [404, 'not_found', 'No such path.'],
        $http === 405 => [405, 'method_not_allowed', 'This path does not accept that method.'],
        $http === 419 => [403, 'csrf', 'Missing or wrong CSRF token.'],
        $http !== 0 => [$http, 'http_error', $e->getMessage()],
        // With APP_DEBUG=false the details stay in the server log.
        default => [500, 'server_error', config('app.debug') ? $e->getMessage() : 'Something went wrong.'],
    };
    return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
}
