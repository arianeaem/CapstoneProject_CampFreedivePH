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
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->enum('rule_type', ['demand', 'seasonality', 'lead_time']);
            $table->string('condition_operator', 10)->nullable(); // e.g. '<=', '>=', '==' for lead_time
            $table->string('condition_value'); // 'high'|'medium'|'low' OR 'peak'|'shoulder'|'off_peak' OR integer string for days
            $table->enum('applies_to', ['all', 'discovery', 'fundive', 'refinement'])->default('all');
            $table->enum('adjustment_type', ['increase', 'decrease'])->default('increase');
            $table->enum('adjustment_method', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('adjustment_value', 10, 2);
            $table->unsignedInteger('priority')->default(1);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'applies_to', 'priority']);
        });

        Schema::create('booking_price_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('pricing_rule_id')->nullable()->constrained('pricing_rules')->nullOnDelete();
            $table->string('rule_name');
            $table->string('rule_type');
            $table->string('condition_summary');
            $table->decimal('base_price', 10, 2);
            $table->decimal('adjustment_amount', 10, 2); // Delta per person (+/-)
            $table->decimal('adjusted_price', 10, 2);   // Price per person after rule
            $table->timestamps();

            $table->index(['booking_id', 'pricing_rule_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_price_adjustments');
        Schema::dropIfExists('pricing_rules');
    }
};
