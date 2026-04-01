<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cameras', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('local_ip', 45)->index();
            $table->string('hostname')->nullable()->index();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('mac_address', 17)->nullable()->index();
            $table->unsignedSmallInteger('http_port')->default(80);
            $table->unsignedSmallInteger('onvif_port')->default(80);
            $table->unsignedSmallInteger('rtsp_port')->default(554);
            $table->string('onvif_path')->default('/onvif/device_service');
            $table->string('rtsp_path')->nullable();
            $table->string('rtsp_transport')->default('tcp');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('supports_onvif')->default(true);
            $table->boolean('supports_rtsp')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cameras');
    }
};