# Paano patakbuhin ang system (Phase 1: Demand Forecast)

Para kay Ari. Simple lang ang mga hakbang. Lahat ng command ay tatakbo sa loob ng folder na nakasaad.

## Kailangan
- **PHP 8.4 o mas bago** (kailangan ng mga package ng Laravel ang 8.4.1 pataas)
- **Composer** at **Node.js** (LTS)
- **Python 3.10 o mas bago**

## A. Laravel (folder: `camp-freedive-ph`)

1. I-install ang mga package (kung wala pang `vendor` folder):
   ```
   composer install
   npm install --ignore-scripts
   ```
2. Gumawa ng `.env` mula sa `.env.example`, tapos ilagay ito sa loob ng `.env`:
   ```
   ML_API_TOKEN=pumili-ng-sariling-token
   ```
   Palitan ng sarili mong token. Dapat **pareho** ito ng `ML_TOKEN` sa Python (hakbang B2).
3. Buuin ang app at database:
   ```
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force
   npm run build
   ```
   (SQLite ang gamit, kaya kailangan munang may file na `database/database.sqlite`. Gumawa ng walang laman na file kung wala pa.)
4. Patakbuhin:
   ```
   php artisan serve
   ```
   Buksan ang `http://localhost:8000/login`
   - Email: `admin@campfreedive.ph`
   - Password: `Password123!`

## B. Python (folder: `demand-forecast`)

1. I-install ang mga kailangan:
   ```
   python -m pip install -r requirements.txt
   ```
2. Gumawa ng file na `.env` sa loob ng `demand-forecast`:
   ```
   ML_TOKEN=pumili-ng-sariling-token
   LARAVEL_API_URL=http://127.0.0.1:8000/api/v1/ml
   ```
3. Dapat **nakabukas** ang Laravel (`php artisan serve`), tapos:
   ```
   python retrain_pipeline.py
   ```
   Ito ang gumagawa ng forecast at nagpapadala sa Laravel.

## B2. Safety service (folder: `safety-forecast`) - optional

Kasama ito sa package, **hindi binago** (kapareho ng orihinal mo). Hindi ito kailangan para tumakbo ang system: kapag hindi ito tumatakbo, bumabalik ang Laravel sa sarili niyang computation ng weather. Patakbuhin lang ito kung gusto mong makita ang buong safety evaluation gamit ang ML (default URL: `http://127.0.0.1:8001`, nakalagay sa `ML_SAFETY_SERVICE_URL`).

## C. Tingnan ang resulta

Buksan ang `http://localhost:8000/admin/demand-forecast`. Makikita:
- ang iisang rules ng High/Medium/Low at Peak/Shoulder/Off-Peak
- ang Model status (versions, "Limited history", paghahambing sa simpleng baseline)
- Forecast **per batch** at **per month**

**Tandaan:** per batch ang forecast, kaya kailangang patakbuhin ulit ang `python retrain_pipeline.py` kapag may bagong batch o booking, para sumama sa listahan.

## D. Mga test

```
# sa camp-freedive-ph
php artisan test

# sa demand-forecast
python -m unittest tests.test_retrain_pipeline -v
```

## Mga dapat malaman

- **Walang hourly weather sa booking page** (ayon sa sinabi mo). Final evaluation lang ang nakikita ng client. Ang hourly data ay ginagamit pa rin sa loob at makikita sa Safety Monitoring. Hindi ginalaw ang weather, booking at validation code mo; galing ang mga ito sa orihinal na bersyon mo.
- Nakaayos na ang sidebar ayon sa order mo: Dashboard, Bookings, Batches, Coaches, Safety Monitoring, Payments & Refunds, Dynamic Pricing, Demand Forecast, Reports & Analytics, Settings.

- Galing sa **553 registration records** ang data (85 batch, Feb 2024 hanggang Nov 2025). Walang synthetic na data.
- **Hindi pa tinatalo ng model ang simpleng average** (hal. "kapareho ng nakaraang batch"). Kulang ang data, kaya may markang "Limited history" at "Not better than simple baselines" sa page.
- Ang mga batch at booking sa database ay **sample/test data** (galing sa seeder at sa pagsubok). Hindi ito totoong customer.
- Naka-**disable** ang nightly `demand:retrain`. Manu-mano lang ang pagpapatakbo ng pipeline.
- Huwag i-commit ang `.env` (may token).
