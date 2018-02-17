<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateJaccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('jaccounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('wechat_id')->unique();
            $table->string('jaccount');
            $table->string('access_token');
            $table->string('refresh_token');
            $table->string('verify_token');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('jaccounts');
    }
}
