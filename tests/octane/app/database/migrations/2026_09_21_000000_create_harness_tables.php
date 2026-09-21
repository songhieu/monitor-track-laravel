<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('customer_id');
        });

        foreach ([1, 2] as $id) {
            DB::table('users')->insert(['id' => $id, 'name' => "User {$id}", 'email' => "user{$id}@harness.test", 'password' => Hash::make('x')]);
        }
        foreach (range(1, 30) as $i) {
            DB::table('customers')->insert(['id' => $i, 'name' => "Customer {$i}"]);
            DB::table('orders')->insert(['id' => $i, 'customer_id' => $i]);
        }
    }
};
