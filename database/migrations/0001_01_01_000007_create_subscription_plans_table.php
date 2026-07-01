<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->smallInteger('min_rooms')->default(1);
            $table->smallInteger('max_rooms')->nullable(); // null = unlimited
            $table->decimal('price_monthly', 10, 3);
            $table->decimal('price_yearly', 10, 3)->nullable();
            $table->char('currency', 3)->default('TND');
            $table->jsonb('features')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('hotel_id')->constrained('hotels');
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->string('status', 20)->default('pending'); // pending | active | expired | suspended | cancelled
            $table->string('billing_cycle', 20)->default('monthly'); // monthly | yearly
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspended_reason')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->jsonb('metadata')->default('{}');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hotel_id'], 'idx_subscriptions_hotel');
            $table->index(['status'], 'idx_subscriptions_status');
            $table->index(['expires_at'], 'idx_subscriptions_expires');
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();
            $table->foreignId('previous_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('new_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscription_id'], 'idx_sub_events_subscription');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('hotel_id')->constrained('hotels');
            $table->foreignUuid('subscription_id')->constrained('subscriptions');
            $table->string('invoice_number', 50)->unique();
            $table->decimal('amount', 10, 3);
            $table->decimal('tax_amount', 10, 3)->default(0);
            $table->decimal('total_amount', 10, 3);
            $table->char('currency', 3)->default('TND');
            $table->string('status', 20)->default('draft'); // draft | sent | paid | overdue | void
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hotel_id'], 'idx_invoices_hotel');
            $table->index(['status'], 'idx_invoices_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
