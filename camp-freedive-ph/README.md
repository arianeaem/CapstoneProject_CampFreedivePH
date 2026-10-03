# Camp Freedive PH — Operations & Marine Safety Monitoring Platform

A web application and maritime safety intelligence platform designed for Camp Freedive PH in Mabini / Anilao, Batangas.

---

## Overview

The platform coordinates weekend freediving operations, dynamic pricing, coach staffing, and automated weather safety evaluations. It integrates with a dedicated Python Machine Learning microservice for marine weather risk assessments, with an automated fallback to native PHP heuristics.

---

## Core Features & Business Rules

### 1. Automated Weekend Batch Lifecycle
- **2D1N Operations**: Batches operate on fixed weekend cycles (Saturday AM to Sunday PM).
- **45-Pax Capacity Limit**: Enforces the 45-participant maximum capacity per weekend batch as mandated by Philippine Coast Guard (PCG) outrigger banca regulations.
- **Distributed Locking**: Uses atomic cache locking (`slot_allocation_lock:{date}`) and temporary 15-minute checkout holds to prevent overbooking.

### 2. Coach Staffing & Safety Ratio
- **1:4 Safety Ratio**: Limits each instructor to a maximum of 4 students per open-water session for diver safety.
- **Staffing Recommendations**: Automatically calculates required coach counts from forecasted student volume.
- **48-Hour Release Cutoff**: Emergency coach release requests close 48 hours prior to 06:30 AM departure.

### 3. Financial Lifecycle & Cancellation Policy
- **₱3,000 / Head Downpayment**: Fixed reservation deposit required to secure slots and prevent cart abandonment.
- **4-Tier Policy Engine**:
  - **Force Majeure (100% Refund / Free Reschedule)**: Active on official PAGASA Typhoon Signals or PCG Gale Warnings.
  - **> 14 Days Out (100% Refund / Free Reschedule)**: Ample advance notice window.
  - **7 to 14 Days Out (Free Reschedule / 0% Refund)**: Downpayment forfeited on cancellation to offset committed room reservations; free reschedule permitted.
  - **< 7 Days Out (Strict Lockout)**: Non-refundable and non-reschedulable due to locked boat charters and instructor commitments.

### 4. Dynamic Yield Management
- **Seasonal Pricing**: Adjusts pricing between Batangas Amihan (peak dry season) and Habagat (monsoon season).
- **Adjustment Cap**: Adjustments are strictly clamped between -30% discount and +30% surge to preserve price fairness.

### 5. Multi-Variable Marine Safety Engine
- **9 Parameters Evaluated**: Significant wave height ($H_s$), wave period ($T_p$), swell ratio, wind speed, wind gusts, current velocity ($u, v$), 3-hour pressure tendency ($\Delta P_{3h}$), precipitation, and storm signals.
- **Compound Synergy**: Compound risk penalties (+15% to +25%) applied for concurrent adverse conditions.
- **Operational Horizon Policy**:
  - $T-1\text{h}$ (Tactical Clearance): Active ML classification with Go/No-Go dockside clearance.
  - $T-6\text{h}/24\text{h}$ (Provisional Trend Outlook): Discrete badge suppressed to prevent false reassurance; surfaces raw physical trajectory and P90 bounds.
  - $T-48\text{h}+$ (Extended Trend Outlook): Long-range planning outlook with physical backstops.

---

## Tech Stack

- **Web Application**: Laravel 12 (PHP 8.2+), Eloquent ORM, MySQL
- **Frontend**: Blade, Alpine.js, Tailwind CSS
- **ML Microservice**: Python 3.10+, FastAPI, ONNX Runtime, XGBoost
- **Payments**: PayMongo API v2 (Hosted Checkout, QR Ph, GCash, Maya, Cards, HMAC-SHA256 Webhooks)
- **Data Ingestion**: Open-Meteo Marine API, Copernicus Marine Service (CMEMS), ECMWF ERA5

---

## Getting Started

### Prerequisites
- PHP >= 8.2 (with `pdo`, `mbstring`, `openssl`, `tokenizer`, `xml`, `curl` extensions)
- Composer
- Node.js >= 18.x & NPM
- Python 3.10+ (for ML Safety Microservice)

### Installation & Setup

1. **Set up the Laravel Application**:
   ```bash
   cd camp-freedive-ph
   composer install
   npm install && npm run build
   cp .env.example .env
   php artisan key:generate
   php artisan migrate --seed
   php artisan serve
   ```

2. **Set up the Python ML Microservice**:
   ```bash
   cd safety-forecast
   python -m venv .venv
   source .venv/bin/activate  # On Windows: .venv\Scripts\activate
   pip install -r requirements.txt
   uvicorn src.serve.main:app --host 127.0.0.1 --port 8001 --reload
   ```

---

## Testing & Verification

Run automated test suites across the stack:

```bash
# Laravel Feature & Unit Tests
php artisan test
php artisan test --filter=BookingFlowTest
php artisan test --filter=MLSafetyServiceIntegrationTest

# Python ML & Safety Threshold Tests
python src/serve/safety_thresholds.py
python src/serve/test_service.py
```

---

## Code Documentation Standards

The codebase follows the 4-Pillar Documentation Framework:
- **Explain the "Why"**: Details domain physics, safety ratios, and business rules.
- **Document Classes & Functions**: Standard docblocks with parameters and return types.
- **Zero Stale References**: Accurate references with zero obsolete terms.
- **Targeted Roadmap Notes**: Clear `TODO` comments for future improvements.
