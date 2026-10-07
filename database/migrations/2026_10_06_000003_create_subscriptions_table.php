<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Plans that have been sold are deactivated rather than deleted,
            // but the snapshot columns below mean a subscription still reads
            // correctly if one ever is.
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();

            // What the trader agreed to, frozen at the moment they chose it,
            // so editing a plan's price never rewrites an existing bill.
            $table->string('plan_name');
            $table->unsignedInteger('amount');
            $table->unsignedSmallInteger('duration_days');

            // pending → active, or → cancelled. Whether an active one is
            // scheduled, running or expired is read from the dates.
            $table->string('status')->default('pending')->index();

            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable()->unique();
            $table->string('payment_channel')->nullable();
            $table->unsignedInteger('amount_paid')->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamp('transfer_reported_at')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('activated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
