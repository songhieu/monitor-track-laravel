<?php

namespace MonitorTrack\Support;

/**
 * Query counters of one unit of work: a request, job, command or scheduled
 * task. Keys are "{connection}\0{normalized sql}".
 */
final class QueryScope
{
    /** @var array<string, array{0:int, 1:float, 2:float}> key => [count, total ms, max ms] */
    public array $counts = [];

    /** @var array<string, array{sql:string, connection:string, site:string, frames:list<array{file:string, line:int, func:string, in_app:bool}>}> N+1 findings by key */
    public array $repeated = [];

    /** @var array<string, array{sql:string, connection:string, site:string, frames:list<array{file:string, line:int, func:string, in_app:bool}>, count:int, total:float, max:float}> slow findings by key + call site */
    public array $slow = [];

    /**
     * @param  string  $kind  request | job | command | schedule; '' = the process scope, named when it ends
     * @param  string  $id  matches the closing event to this scope
     */
    public function __construct(public string $kind = '', public string $name = '', public string $id = '')
    {
    }
}
