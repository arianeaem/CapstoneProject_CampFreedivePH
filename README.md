# CampFreedivePH

Booking, operations and marine-safety platform for Camp Freedive PH (Anilao, Mabini, Batangas).
This repository holds three projects that work together:

| Folder | What it is | Tech |
|---|---|---|
| [`camp-freedive-ph/`](camp-freedive-ph/README.md) | The website: public booking, Manage Booking, and the owner / admin / coach portals | Laravel 13, PHP 8.4+, PostgreSQL (Supabase), Vite + Tailwind + Alpine.js |
| [`safety-forecast/`](safety-forecast/README.md) | Marine weather model service (research). Forecasts waves, wind and currents and rates each dive day from Very Safe to Critical Risk. Not used by the website | Python, FastAPI, XGBoost / ONNX |
| `demand-forecast/` | Booking-demand model. Forecasts how busy each batch and month will be and sends the results to the website | Python, scikit-learn |

How they connect:

```
Open-Meteo APIs ──▶ camp-freedive-ph ◀── demand-forecast (nightly GitHub Action)
 weather + marine       website           demand per batch / month

safety-forecast (FastAPI :8001, local only, not connected to the website)
```

The website rates each dive day itself, from Open-Meteo data and its own weather rules
(`WeatherForecastService`, `WeatherSafetyService`). It stopped calling the safety-forecast service in
commit `ae6b4e7`; that service now runs only on a developer machine for model research.

## Getting started

### 1. Website (`camp-freedive-ph/`)

```bash
cd camp-freedive-ph
composer install
npm install && npm run build
cp .env.example .env        # then fill in database, mail and API keys
php artisan key:generate
php artisan migrate
php artisan serve           # http://127.0.0.1:8000
```

Tests: `php artisan test` (uses an in-memory SQLite database, never the real one).

### 2. Safety model service (`safety-forecast/`)

The trained models in `safety-forecast/models/` are stored with **Git LFS**. Install it once before cloning, or run `git lfs pull` afterwards:

```bash
git lfs install
git lfs pull
cd safety-forecast
python -m venv .venv && .venv/Scripts/activate   # macOS/Linux: source .venv/bin/activate
pip install -r requirements.txt
uvicorn src.serve.main:app --host 127.0.0.1 --port 8001
```

Tests: `pytest tests`. One-off research and data-repair scripts live in `safety-forecast/tools/`.

### 3. Demand model (`demand-forecast/`)

```bash
cd demand-forecast
pip install -r requirements.txt
python retrain_pipeline.py --include-laravel
```

This runs automatically every night through `.github/workflows/demand-forecast.yml`.

## Deployment

- **Website:** every push to `feature/prd-forecast` builds and deploys to Azure App Service
  (`.github/workflows/feature-prd-forecast_camp-freedive-ph.yml`). nginx serves only
  `camp-freedive-ph/public/`, using the config in `camp-freedive-ph/deploy/nginx/default.conf`.
- **CI:** `.github/workflows/laravel.yml` runs the website tests on pushes and pull requests.

## What is not in git

- Secrets (`.env` files)
- Python environments and caches (`.venv/`, `__pycache__/`, `.pytest_cache/`)
- `safety-forecast/archive/` (retired models and old data, still available in git history)
- Generated report folders in `safety-forecast/reports/` (charts and one-off runs). They can be rebuilt with the training scripts.
