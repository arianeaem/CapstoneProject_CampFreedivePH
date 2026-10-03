@php
    $forecast = $data['forecast'] ?? [];
    $horizons = $forecast['horizon_summaries'] ?? [];
    $monthlyHorizons = $forecast['monthly_horizons'] ?? [];
    $monthlyClassifications = $forecast['monthly_classifications'] ?? [];
    $trend = $data['forecast_trend'] ?? [];
    $syncedAt = $forecast['synced_at'] ?? null;
    $source = $forecast['source'] ?? 'ML Model';
@endphp

<!-- AI Demand & Revenue Horizon Forecasting Component -->
<div class="space-y-6"
     x-data="{
         selectedHorizon: '30',
         monthlyData: @js($monthlyHorizons),
         monthlyClassifications: @js($monthlyClassifications),
         trendData: @js($trend),

         get currentCards() {
             return this.monthlyData[this.selectedHorizon] || this.monthlyData[this.selectedHorizon + '_day'] || [];
         },

         get monthGroups() {
             const actuals = (this.trendData || []).filter(item => item.type === 'actual');
             const forecastItems = (this.currentCards || []).map(card => ({
                 month_key: card.month_key,
                 type: 'forecast',
                 month_name: card.month_name,
                 short_name: card.short_name,
                 participants: card.diver_volume,
                 bookings: card.estimated_bookings,
                 revenue_php: card.projected_revenue,
                 coaches: card.coaches_needed,
                 batches_count: card.batches_count,
                 demand_level: card.demand_classification,
                 season_period: card.classification ? (card.classification + ' Season') : card.peak_classification,
                 classification: card.classification,
                 monthly_average: card.monthly_average
             }));

             const groupsMap = new Map();

             const getShortMonth = (key, label) => {
                 if (key) {
                     const parts = key.split('-');
                     if (parts.length >= 2) {
                         const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                         const mIdx = parseInt(parts[1], 10) - 1;
                         if (mIdx >= 0 && mIdx < 12) return monthNames[mIdx];
                     }
                 }
                 if (label) {
                     return label.split(' ')[0];
                 }
                 return key;
             };

             for (const it of actuals) {
                 const key = it.month_key;
                 if (!groupsMap.has(key)) {
                     groupsMap.set(key, {
                         month_key: key,
                         month_label: getShortMonth(key, it.label),
                         full_month_name: it.label || key,
                         actual: null,
                         forecast: null,
                     });
                 }
                 groupsMap.get(key).actual = {
                     ...it,
                     display_title: it.label || getShortMonth(key, it.label)
                 };
             }

             for (const it of forecastItems) {
                 const key = it.month_key;
                 if (!groupsMap.has(key)) {
                     groupsMap.set(key, {
                         month_key: key,
                         month_label: getShortMonth(key, it.short_name),
                         full_month_name: it.month_name || key,
                         actual: null,
                         forecast: null,
                     });
                 }
                 groupsMap.get(key).forecast = {
                     ...it,
                     display_title: it.month_name || getShortMonth(key, it.short_name)
                 };
             }

             const sortedKeys = Array.from(groupsMap.keys()).sort();
             return sortedKeys.map(k => groupsMap.get(k));
         },

         getMax(metric) {
             const groups = this.monthGroups;
             if (!groups || groups.length === 0) return 1;
             let max = 0;
             for (const g of groups) {
                 for (const item of [g.actual, g.forecast]) {
                     if (!item) continue;
                     let val = 0;
                     if (metric === 'revenue') val = item.revenue_php || 0;
                     else if (metric === 'bookings') val = item.bookings || 0;
                     else if (metric === 'coaches') val = item.coaches || 0;
                     else val = item.participants || 0;
                     if (val > max) max = val;
                 }
             }
             if (metric === 'revenue') return Math.max(max, 10000);
             if (metric === 'coaches') return Math.max(max, 4);
             if (metric === 'bookings') return Math.max(max, 5);
             return Math.max(max, 10);
         },

         getBarHeight(item, metric) {
             if (!item) return 0;
             let val = 0;
             if (metric === 'revenue') val = item.revenue_php || 0;
             else if (metric === 'bookings') val = item.bookings || 0;
             else if (metric === 'coaches') val = item.coaches || 0;
             else val = item.participants || 0;

             const max = this.getMax(metric);
             const pct = Math.round((val / max) * 100);
             return Math.max(pct, 6);
         },

         formatValue(val, metric) {
             if (metric === 'revenue') {
                 if (val >= 1000000) return '₱' + (val / 1000000).toFixed(1) + 'M';
                 if (val >= 1000) return '₱' + (val / 1000).toFixed(0) + 'k';
                 return '₱' + Math.round(val).toLocaleString();
             }
             return Math.round(val).toLocaleString();
         },

         formatCurrency(val) {
             return '₱' + Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
         }
     }">
    
    <!-- Top Section: Horizon Projection Period Selector & Month Cards -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 shadow-2xs space-y-5">
        
        <!-- Top Row: Title, Sync Badge & Projection Period Selector -->
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-base sm:text-lg font-extrabold text-[#1D1D1F]">Demand & Revenue Forecasting</h2>
                </div>
                <p class="text-xs sm:text-sm text-[#6E6E73] mt-1">
                    Estimated guest demand, bookings, and projected revenue for upcoming months to help plan camp schedules and staffing.
                </p>
            </div>

            <!-- Controls: Period Selector & Sync Indicator -->
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 w-full lg:w-auto">
                @if($syncedAt)
                    <div class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-semibold text-[#6E6E73] bg-[#F2F2F7] px-2.5 py-1.5 rounded-lg self-start sm:self-auto">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>Synced {{ \Carbon\Carbon::parse($syncedAt)->diffForHumans() }}</span>
                    </div>
                @endif

                <!-- Projection Period Selector Tabs (7, 30, 60, 90 Days) -->
                <div class="grid grid-cols-4 sm:inline-flex items-center p-1 bg-[#F2F2F7] rounded-xl border border-[#E5E5EA] w-full sm:w-auto">
                    <button type="button"
                            @click="selectedHorizon = '7'"
                            :class="selectedHorizon === '7' ? 'bg-white text-[#780000] font-black shadow-2xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                            class="px-2.5 sm:px-3 py-1.5 text-xs sm:text-sm rounded-lg transition-all duration-150 cursor-pointer text-center">
                        7 Days
                    </button>
                    <button type="button"
                            @click="selectedHorizon = '30'"
                            :class="selectedHorizon === '30' ? 'bg-white text-[#780000] font-black shadow-2xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                            class="px-2.5 sm:px-3 py-1.5 text-xs sm:text-sm rounded-lg transition-all duration-150 cursor-pointer text-center">
                        30 Days
                    </button>
                    <button type="button"
                            @click="selectedHorizon = '60'"
                            :class="selectedHorizon === '60' ? 'bg-white text-[#780000] font-black shadow-2xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                            class="px-2.5 sm:px-3 py-1.5 text-xs sm:text-sm rounded-lg transition-all duration-150 cursor-pointer text-center">
                        60 Days
                    </button>
                    <button type="button"
                            @click="selectedHorizon = '90'"
                            :class="selectedHorizon === '90' ? 'bg-white text-[#780000] font-black shadow-2xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                            class="px-2.5 sm:px-3 py-1.5 text-xs sm:text-sm rounded-lg transition-all duration-150 cursor-pointer text-center">
                        90 Days
                    </button>
                </div>
            </div>
        </div>

        <!-- Dynamic Month Cards Grid (Based on Selected Projection Period) -->
        <div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 mb-3">
                <span class="text-xs sm:text-sm font-black uppercase tracking-wider text-[#6E6E73]">
                    Projected Months (<span x-text="currentCards.length"></span>)
                </span>
                <span class="text-xs sm:text-sm font-semibold text-[#8E8E93]" x-text="'Forecast for the next ' + selectedHorizon + ' days'"></span>
            </div>

            <!-- Month Cards Container -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                
                <template x-for="(card, index) in currentCards" :key="card.month_key || index">
                    <div class="p-4 sm:p-5 rounded-2xl bg-gradient-to-b from-[#780000]/[0.04] via-white to-white border border-[#E5E5EA] shadow-2xs space-y-3.5 relative overflow-hidden">
                        
                        <!-- Ambient Flowing Glows (Fintech Glassmorphism Feel) -->
                        <div class="absolute -top-10 -right-10 w-36 h-36 bg-gradient-to-br from-[#780000]/20 via-[#9E2A2B]/12 to-transparent rounded-full blur-2xl pointer-events-none"></div>
                        <div class="absolute -top-8 left-1/4 w-32 h-20 bg-gradient-to-b from-[#780000]/10 to-transparent rounded-full blur-xl pointer-events-none"></div>

                        <!-- Top Header: Month Name on left, Demand & Season stacked on right -->
                        <div class="relative z-10 flex items-start justify-between gap-3">
                            <h4 class="text-base font-black text-[#1D1D1F] pt-0.5" x-text="card.month_name"></h4>
                            
                            <!-- Badges Column: Demand on top, Season at the bottom of demand -->
                            <div class="flex flex-col items-end gap-1 shrink-0">
                                <!-- Demand Classification Badge (No border) -->
                                <span class="text-[11px] font-black uppercase tracking-wider px-2.5 py-0.5 rounded-full whitespace-nowrap"
                                      :class="{
                                          'bg-amber-50 text-amber-800': card.demand_classification === 'High',
                                          'bg-emerald-50 text-emerald-800': card.demand_classification === 'Medium',
                                          'bg-slate-100 text-slate-700': card.demand_classification === 'Low'
                                      }"
                                      x-text="card.demand_classification + ' Demand'">
                                </span>

                                <!-- Dynamic Statistical Season Classification Badge (Forecast-Driven) -->
                                <div class="flex flex-col items-end">
                                    <span class="text-xs font-bold px-2 py-0.5 rounded-md whitespace-nowrap inline-flex items-center"
                                          :class="{
                                              'bg-rose-50 text-[#780000]': (card.classification === 'Peak' || card.peak_classification.includes('Peak')) && !card.peak_classification.includes('Off'),
                                              'bg-teal-50 text-teal-800': (card.classification === 'Shoulder' || card.peak_classification.includes('Shoulder')),
                                              'bg-slate-100 text-slate-700': (card.classification === 'Off-Peak' || card.peak_classification.includes('Off'))
                                          }"
                                          :title="card.monthly_average ? ('Forecast-driven: ' + card.monthly_average + ' pax/batch average vs statistical thresholds [' + (card.lower_threshold || 15.4) + ' - ' + (card.upper_threshold || 19.6) + ']') : 'Forecast-driven statistical classification'">
                                        <span x-text="card.classification ? (card.classification + ' Season') : card.peak_classification"></span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Estimated Revenue (Primary Metric) -->
                        <div class="relative z-10 pt-0.5">
                            <span class="text-[11px] font-bold text-[#6E6E73] uppercase tracking-wider block">Estimated Revenue</span>
                            <div class="text-lg sm:text-xl font-black text-[#780000] tracking-tight mt-0.5 truncate" x-text="formatCurrency(card.projected_revenue)"></div>
                        </div>

                        <!-- Secondary Metrics Grid (Easy to Understand) -->
                        <div class="relative z-10 pt-2 border-t border-[#F2F2F7] space-y-1.5 text-xs sm:text-sm text-[#6E6E73]">
                            
                            <!-- Expected Guests -->
                            <div class="flex items-center justify-between">
                                <span>Expected Guests:</span>
                                <strong class="text-[#1D1D1F] font-extrabold" x-text="card.diver_volume + ' Guests'"></strong>
                            </div>

                            <!-- Average Pax per Batch (Statistical Demand Benchmark) -->
                            <template x-if="card.monthly_average">
                                <div class="flex items-center justify-between">
                                    <span>Avg Pax / Batch:</span>
                                    <span class="font-bold text-[#1D1D1F]">
                                        <span x-text="card.monthly_average"></span>
                                        <span class="font-normal text-[#8E8E93]"> pax</span>
                                    </span>
                                </div>
                            </template>

                            <!-- Expected Bookings -->
                            <div class="flex items-center justify-between">
                                <span>Expected Bookings:</span>
                                <strong class="text-[#1D1D1F] font-extrabold" x-text="card.estimated_bookings + ' Bookings'"></strong>
                            </div>

                            <!-- Planned Batches -->
                            <div class="flex items-center justify-between">
                                <span>Planned Batches:</span>
                                <span class="font-bold text-[#1D1D1F]" x-text="card.batches_count + ' ' + (card.batches_count === 1 ? 'Batch' : 'Batches')"></span>
                            </div>

                            <!-- Coaches per Batch Needed -->
                            <div class="flex items-center justify-between pt-1.5 text-[#780000] font-black">
                                <span>Coaches per Batch:</span>
                                <span class="bg-[#780000]/10 px-2 py-0.5 rounded-md text-xs font-black" x-text="card.coaches_needed + ' ' + (card.coaches_needed === 1 ? 'Coach' : 'Coaches')"></span>
                            </div>

                        </div>

                    </div>
                </template>

                <!-- Empty State if no cards for selected period -->
                <div x-show="currentCards.length === 0" class="col-span-full p-8 text-center bg-[#F2F2F7] rounded-xl border border-dashed border-[#E5E5EA] text-sm text-[#8E8E93]">
                    No forecast data available for the selected period.
                </div>

            </div>
        </div>

    </div>

    <!-- 4 Separate Visual Cards: Divers, Bookings, Revenue, Coaches (2x2 Grid) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">

        <!-- Visual Card 1: Guest Turnout & Demand Trend -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs space-y-4 flex flex-col justify-between">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">Guest Turnout & Demand</h3>
                    <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">Past attendance compared with projected number of guests each month</p>
                </div>
                <div class="flex items-center gap-3 text-xs sm:text-sm shrink-0 self-start sm:self-auto">
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-[#780000]"></span>
                        <span class="font-bold text-[#1D1D1F]">Actual (Past)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-[#00C3D0]"></span>
                        <span class="font-bold text-[#1D1D1F]">Forecast (Expected)</span>
                    </div>
                </div>
            </div>

            <!-- Chart Container -->
            <div class="pt-2">
                <div class="flex items-end justify-around gap-2 sm:gap-4 h-52 pt-8 pb-2 px-2 sm:px-4  relative overflow-visible">
                    
                    <!-- Horizontal Background Grid Lines -->
                    <div class="absolute inset-0 flex flex-col justify-between pt-8 pb-2 px-2 pointer-events-none z-0">
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                    </div>

                    <!-- Month Clustered Bars -->
                    <template x-for="(group, gIdx) in monthGroups" :key="'divers_' + group.month_key">
                        <div class="flex-1 flex flex-col items-center h-full justify-end relative z-10 px-1 sm:px-2 max-w-[110px] sm:max-w-[130px] lg:max-w-[150px]">
                            
                            <div class="flex items-end justify-center gap-1.5 sm:gap-2.5 w-full h-full">
                                
                                <!-- Actual Bar -->
                                <template x-if="group.actual">
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative w-full transition-all"
                                         :class="(group.actual && group.forecast) ? 'max-w-[38px] sm:max-w-[48px] lg:max-w-[56px]' : 'max-w-[56px] sm:max-w-[70px] lg:max-w-[80px]'">
                                        
                                        <!-- Hover Tooltip -->
                                        <div class="absolute bottom-full mb-2.5 left-1/2 -translate-x-1/2 min-w-[180px] sm:min-w-[200px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-30 pointer-events-none shadow-xl space-y-1.5 text-left">
                                            <div class="flex items-center justify-between gap-1.5 pb-1 border-b border-white/10 flex-wrap">
                                                <span class="font-bold text-xs text-[#F1D5D5] break-words" x-text="group.actual.display_title"></span>
                                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black shrink-0 tracking-wider bg-[#780000] text-white">Actual</span>
                                            </div>
                                            <div class="text-white font-black text-xs sm:text-sm" x-text="group.actual.participants + ' Guests'"></div>
                                            <div class="text-gray-300 text-xs flex items-center justify-between">
                                                <span>Demand:</span>
                                                <span class="font-bold text-white" x-text="group.actual.demand_level"></span>
                                            </div>
                                        </div>

                                        <!-- Value Label Above Bar -->
                                        <span class="text-xs sm:text-sm font-black mb-1 truncate max-w-full text-[#780000]"
                                              x-text="group.actual.participants">
                                        </span>

                                        <!-- Bar (Solid Maroon, Wider Desktop Profile) -->
                                        <div class="w-full rounded-t-md transition-all duration-200 group-hover:opacity-90 origin-bottom relative cursor-pointer bg-[#780000]"
                                             :style="'height: ' + getBarHeight(group.actual, 'divers') + '%;'">
                                        </div>

                                    </div>
                                </template>

                                <!-- Forecast Bar -->
                                <template x-if="group.forecast">
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative w-full transition-all"
                                         :class="(group.actual && group.forecast) ? 'max-w-[38px] sm:max-w-[48px] lg:max-w-[56px]' : 'max-w-[56px] sm:max-w-[70px] lg:max-w-[80px]'">
                                        
                                        <!-- Hover Tooltip -->
                                        <div class="absolute bottom-full mb-2.5 left-1/2 -translate-x-1/2 min-w-[180px] sm:min-w-[200px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-30 pointer-events-none shadow-xl space-y-1.5 text-left">
                                            <div class="flex items-center justify-between gap-1.5 pb-1 border-b border-white/10 flex-wrap">
                                                <span class="font-bold text-xs text-sky-200 break-words" x-text="group.forecast.display_title"></span>
                                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black shrink-0 tracking-wider bg-[#003049] text-white">Forecast</span>
                                            </div>
                                            <div class="text-white font-black text-xs sm:text-sm" x-text="group.forecast.participants + ' Guests'"></div>
                                            <div class="text-gray-300 text-xs flex items-center justify-between">
                                                <span>Demand:</span>
                                                <span class="font-bold text-white" x-text="group.forecast.demand_level"></span>
                                            </div>
                                        </div>

                                        <!-- Value Label Above Bar -->
                                        <span class="text-xs sm:text-sm font-black mb-1 truncate max-w-full text-[#003049]"
                                              x-text="group.forecast.participants">
                                        </span>

                                        <!-- Bar (Solid Ocean Navy, Wider Desktop Profile) -->
                                        <div class="w-full rounded-t-md transition-all duration-200 group-hover:opacity-90 origin-bottom relative cursor-pointer bg-[#00C3D0]"
                                             :style="'height: ' + getBarHeight(group.forecast, 'divers') + '%;'">
                                        </div>

                                    </div>
                                </template>

                            </div>

                        </div>
                    </template>
                </div>

                <!-- Labels (Short Month Only, No Year) -->
                <div class="flex items-center justify-around gap-2 sm:gap-4 px-2 sm:px-4 text-center pt-2">
                    <template x-for="(group, gIdx) in monthGroups" :key="'label_divers_' + group.month_key">
                        <div class="flex-1 max-w-[110px] sm:max-w-[130px] lg:max-w-[150px] min-w-0 truncate" :title="group.full_month_name">
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block truncate" x-text="group.month_label"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Visual Card 2: Monthly Bookings Trend -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs space-y-4 flex flex-col justify-between">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">Monthly Bookings</h3>
                    <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">Completed bookings compared with expected future reservation groups</p>
                </div>
                <div class="flex items-center gap-3 text-xs sm:text-sm shrink-0 self-start sm:self-auto">
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-[#780000]"></span>
                        <span class="font-bold text-[#1D1D1F]">Actual (Past)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-[#00C3D0]"></span>
                        <span class="font-bold text-[#1D1D1F]">Forecast (Expected)</span>
                    </div>
                </div>
            </div>

            <!-- Chart Container -->
            <div class="pt-2">
                <div class="flex items-end justify-around gap-2 sm:gap-4 h-52 pt-8 pb-2 px-2 sm:px-4  relative overflow-visible">
                    
                    <!-- Horizontal Background Grid Lines -->
                    <div class="absolute inset-0 flex flex-col justify-between pt-8 pb-2 px-2 pointer-events-none z-0">
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                    </div>

                    <!-- Month Clustered Bars -->
                    <template x-for="(group, gIdx) in monthGroups" :key="'bookings_' + group.month_key">
                        <div class="flex-1 flex flex-col items-center h-full justify-end relative z-10 px-1 sm:px-2 max-w-[110px] sm:max-w-[130px] lg:max-w-[150px]">
                            
                            <div class="flex items-end justify-center gap-1.5 sm:gap-2.5 w-full h-full">
                                
                                <!-- Actual Bar -->
                                <template x-if="group.actual">
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative w-full transition-all"
                                         :class="(group.actual && group.forecast) ? 'max-w-[38px] sm:max-w-[48px] lg:max-w-[56px]' : 'max-w-[56px] sm:max-w-[70px] lg:max-w-[80px]'">
                                        
                                        <!-- Hover Tooltip -->
                                        <div class="absolute bottom-full mb-2.5 left-1/2 -translate-x-1/2 min-w-[180px] sm:min-w-[200px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-30 pointer-events-none shadow-xl space-y-1.5 text-left">
                                            <div class="flex items-center justify-between gap-1.5 pb-1 border-b border-white/10 flex-wrap">
                                                <span class="font-bold text-xs text-[#F1D5D5] break-words" x-text="group.actual.display_title"></span>
                                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black shrink-0 tracking-wider bg-[#780000] text-white">Actual</span>
                                            </div>
                                            <div class="text-white font-black text-xs sm:text-sm" x-text="group.actual.bookings + ' Bookings'"></div>
                                            <div class="text-gray-300 text-xs flex items-center justify-between">
                                                <span>Batches:</span>
                                                <span class="font-bold text-white" x-text="group.actual.batches_count"></span>
                                            </div>
                                        </div>

                                        <!-- Value Label Above Bar -->
                                        <span class="text-xs sm:text-sm font-black mb-1 truncate max-w-full text-[#780000]"
                                              x-text="group.actual.bookings">
                                        </span>

                                        <!-- Bar (Solid Maroon, Wider Desktop Profile) -->
                                        <div class="w-full rounded-t-md transition-all duration-200 group-hover:opacity-90 origin-bottom relative cursor-pointer bg-[#780000]"
                                             :style="'height: ' + getBarHeight(group.actual, 'bookings') + '%;'">
                                        </div>

                                    </div>
                                </template>

                                <!-- Forecast Bar -->
                                <template x-if="group.forecast">
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative w-full transition-all"
                                         :class="(group.actual && group.forecast) ? 'max-w-[38px] sm:max-w-[48px] lg:max-w-[56px]' : 'max-w-[56px] sm:max-w-[70px] lg:max-w-[80px]'">
                                        
                                        <!-- Hover Tooltip -->
                                        <div class="absolute bottom-full mb-2.5 left-1/2 -translate-x-1/2 min-w-[180px] sm:min-w-[200px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-30 pointer-events-none shadow-xl space-y-1.5 text-left">
                                            <div class="flex items-center justify-between gap-1.5 pb-1 border-b border-white/10 flex-wrap">
                                                <span class="font-bold text-xs text-sky-200 break-words" x-text="group.forecast.display_title"></span>
                                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black shrink-0 tracking-wider bg-[#003049] text-white">Forecast</span>
                                            </div>
                                            <div class="text-white font-black text-xs sm:text-sm" x-text="group.forecast.bookings + ' Bookings'"></div>
                                            <div class="text-gray-300 text-xs flex items-center justify-between">
                                                <span>Batches:</span>
                                                <span class="font-bold text-white" x-text="group.forecast.batches_count"></span>
                                            </div>
                                        </div>

                                        <!-- Value Label Above Bar -->
                                        <span class="text-xs sm:text-sm font-black mb-1 truncate max-w-full text-[#003049]"
                                              x-text="group.forecast.bookings">
                                        </span>

                                        <!-- Bar (Solid Ocean Navy, Wider Desktop Profile) -->
                                        <div class="w-full rounded-t-md transition-all duration-200 group-hover:opacity-90 origin-bottom relative cursor-pointer bg-[#00C3D0]"
                                             :style="'height: ' + getBarHeight(group.forecast, 'bookings') + '%;'">
                                        </div>

                                    </div>
                                </template>

                            </div>

                        </div>
                    </template>
                </div>

                <!-- Labels (Short Month Only, No Year) -->
                <div class="flex items-center justify-around gap-2 sm:gap-4 px-2 sm:px-4 text-center pt-2">
                    <template x-for="(group, gIdx) in monthGroups" :key="'label_bookings_' + group.month_key">
                        <div class="flex-1 max-w-[110px] sm:max-w-[130px] lg:max-w-[150px] min-w-0 truncate" :title="group.full_month_name">
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block truncate" x-text="group.month_label"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Visual Card 3: Monthly Revenue Trend -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs space-y-4 flex flex-col justify-between">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">Monthly Revenue</h3>
                    <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">Collected revenue compared with estimated future course earnings</p>
                </div>
                <div class="flex items-center gap-3 text-xs sm:text-sm shrink-0 self-start sm:self-auto">
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-[#780000]"></span>
                        <span class="font-bold text-[#1D1D1F]">Actual (Past)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-[#00C3D0]"></span>
                        <span class="font-bold text-[#1D1D1F]">Forecast (Expected)</span>
                    </div>
                </div>
            </div>

            <!-- Chart Container -->
            <div class="pt-2">
                <div class="flex items-end justify-around gap-2 sm:gap-4 h-52 pt-8 pb-2 px-2 sm:px-4  relative overflow-visible">
                    
                    <!-- Horizontal Background Grid Lines -->
                    <div class="absolute inset-0 flex flex-col justify-between pt-8 pb-2 px-2 pointer-events-none z-0">
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                    </div>

                    <!-- Month Clustered Bars -->
                    <template x-for="(group, gIdx) in monthGroups" :key="'revenue_' + group.month_key">
                        <div class="flex-1 flex flex-col items-center h-full justify-end relative z-10 px-1 sm:px-2 max-w-[110px] sm:max-w-[130px] lg:max-w-[150px]">
                            
                            <div class="flex items-end justify-center gap-1.5 sm:gap-2.5 w-full h-full">
                                
                                <!-- Actual Bar -->
                                <template x-if="group.actual">
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative w-full transition-all"
                                         :class="(group.actual && group.forecast) ? 'max-w-[38px] sm:max-w-[48px] lg:max-w-[56px]' : 'max-w-[56px] sm:max-w-[70px] lg:max-w-[80px]'">
                                        
                                        <!-- Hover Tooltip -->
                                        <div class="absolute bottom-full mb-2.5 left-1/2 -translate-x-1/2 min-w-[180px] sm:min-w-[200px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-30 pointer-events-none shadow-xl space-y-1.5 text-left">
                                            <div class="flex items-center justify-between gap-1.5 pb-1 border-b border-white/10 flex-wrap">
                                                <span class="font-bold text-xs text-[#F1D5D5] break-words" x-text="group.actual.display_title"></span>
                                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black shrink-0 tracking-wider bg-[#780000] text-white">Actual</span>
                                            </div>
                                            <div class="text-white font-black text-xs sm:text-sm" x-text="formatCurrency(group.actual.revenue_php)"></div>
                                            <div class="text-gray-300 text-xs flex items-center justify-between">
                                                <span>Guests:</span>
                                                <span class="font-bold text-white" x-text="group.actual.participants"></span>
                                            </div>
                                        </div>

                                        <!-- Value Label Above Bar -->
                                        <span class="text-xs sm:text-sm font-black mb-1 truncate max-w-full text-[#780000]"
                                              x-text="formatValue(group.actual.revenue_php, 'revenue')">
                                        </span>

                                        <!-- Bar (Solid Maroon, Wider Desktop Profile) -->
                                        <div class="w-full rounded-t-md transition-all duration-200 group-hover:opacity-90 origin-bottom relative cursor-pointer bg-[#780000]"
                                             :style="'height: ' + getBarHeight(group.actual, 'revenue') + '%;'">
                                        </div>

                                    </div>
                                </template>

                                <!-- Forecast Bar -->
                                <template x-if="group.forecast">
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative w-full transition-all"
                                         :class="(group.actual && group.forecast) ? 'max-w-[38px] sm:max-w-[48px] lg:max-w-[56px]' : 'max-w-[56px] sm:max-w-[70px] lg:max-w-[80px]'">
                                        
                                        <!-- Hover Tooltip -->
                                        <div class="absolute bottom-full mb-2.5 left-1/2 -translate-x-1/2 min-w-[180px] sm:min-w-[200px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-30 pointer-events-none shadow-xl space-y-1.5 text-left">
                                            <div class="flex items-center justify-between gap-1.5 pb-1 border-b border-white/10 flex-wrap">
                                                <span class="font-bold text-xs text-sky-200 break-words" x-text="group.forecast.display_title"></span>
                                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black shrink-0 tracking-wider bg-[#003049] text-white">Forecast</span>
                                            </div>
                                            <div class="text-white font-black text-xs sm:text-sm" x-text="formatCurrency(group.forecast.revenue_php)"></div>
                                            <div class="text-gray-300 text-xs flex items-center justify-between">
                                                <span>Guests:</span>
                                                <span class="font-bold text-white" x-text="group.forecast.participants"></span>
                                            </div>
                                        </div>

                                        <!-- Value Label Above Bar -->
                                        <span class="text-xs sm:text-sm font-black mb-1 truncate max-w-full text-[#003049]"
                                              x-text="formatValue(group.forecast.revenue_php, 'revenue')">
                                        </span>

                                        <!-- Bar (Solid Ocean Navy, Wider Desktop Profile) -->
                                        <div class="w-full rounded-t-md transition-all duration-200 group-hover:opacity-90 origin-bottom relative cursor-pointer bg-[#00C3D0]"
                                             :style="'height: ' + getBarHeight(group.forecast, 'revenue') + '%;'">
                                        </div>

                                    </div>
                                </template>

                            </div>

                        </div>
                    </template>
                </div>

                <!-- Labels (Short Month Only, No Year) -->
                <div class="flex items-center justify-around gap-2 sm:gap-4 px-2 sm:px-4 text-center pt-2">
                    <template x-for="(group, gIdx) in monthGroups" :key="'label_revenue_' + group.month_key">
                        <div class="flex-1 max-w-[110px] sm:max-w-[130px] lg:max-w-[150px] min-w-0 truncate" :title="group.full_month_name">
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block truncate" x-text="group.month_label"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Visual Card 4: Recommended Coaches per Trip -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs space-y-4 flex flex-col justify-between">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">Recommended Coaches per Trip</h3>
                    <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">Coaches needed per batch to maintain safety ratios for expected guest turnout</p>
                </div>
                <div class="flex items-center gap-3 text-xs sm:text-sm shrink-0 self-start sm:self-auto">
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-[#780000]"></span>
                        <span class="font-bold text-[#1D1D1F]">Actual (Past)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-[#00C3D0]"></span>
                        <span class="font-bold text-[#1D1D1F]">Forecast (Expected)</span>
                    </div>
                </div>
            </div>

            <!-- Chart Container -->
            <div class="pt-2">
                <div class="flex items-end justify-around gap-2 sm:gap-4 h-52 pt-8 pb-2 px-2 sm:px-4  relative overflow-visible">
                    
                    <!-- Horizontal Background Grid Lines -->
                    <div class="absolute inset-0 flex flex-col justify-between pt-8 pb-2 px-2 pointer-events-none z-0">
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                    </div>

                    <!-- Month Clustered Bars -->
                    <template x-for="(group, gIdx) in monthGroups" :key="'coaches_' + group.month_key">
                        <div class="flex-1 flex flex-col items-center h-full justify-end relative z-10 px-1 sm:px-2 max-w-[110px] sm:max-w-[130px] lg:max-w-[150px]">
                            
                            <div class="flex items-end justify-center gap-1.5 sm:gap-2.5 w-full h-full">
                                
                                <!-- Actual Bar -->
                                <template x-if="group.actual">
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative w-full transition-all"
                                         :class="(group.actual && group.forecast) ? 'max-w-[38px] sm:max-w-[48px] lg:max-w-[56px]' : 'max-w-[56px] sm:max-w-[70px] lg:max-w-[80px]'">
                                        
                                        <!-- Hover Tooltip -->
                                        <div class="absolute bottom-full mb-2.5 left-1/2 -translate-x-1/2 min-w-[180px] sm:min-w-[200px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-30 pointer-events-none shadow-xl space-y-1.5 text-left">
                                            <div class="flex items-center justify-between gap-1.5 pb-1 border-b border-white/10 flex-wrap">
                                                <span class="font-bold text-xs text-[#F1D5D5] break-words" x-text="group.actual.display_title"></span>
                                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black shrink-0 tracking-wider bg-[#780000] text-white">Actual</span>
                                            </div>
                                            <div class="text-white font-black text-xs sm:text-sm" x-text="group.actual.coaches + ' Coaches'"></div>
                                            <div class="text-gray-300 text-xs flex items-center justify-between">
                                                <span>Ratio:</span>
                                                <span class="font-bold text-white">~1 coach / 4 pax</span>
                                            </div>
                                        </div>

                                        <!-- Value Label Above Bar -->
                                        <span class="text-xs sm:text-sm font-black mb-1 truncate max-w-full text-[#780000]"
                                              x-text="group.actual.coaches">
                                        </span>

                                        <!-- Bar (Solid Maroon, Wider Desktop Profile) -->
                                        <div class="w-full rounded-t-md transition-all duration-200 group-hover:opacity-90 origin-bottom relative cursor-pointer bg-[#780000]"
                                             :style="'height: ' + getBarHeight(group.actual, 'coaches') + '%;'">
                                        </div>

                                    </div>
                                </template>

                                <!-- Forecast Bar -->
                                <template x-if="group.forecast">
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative w-full transition-all"
                                         :class="(group.actual && group.forecast) ? 'max-w-[38px] sm:max-w-[48px] lg:max-w-[56px]' : 'max-w-[56px] sm:max-w-[70px] lg:max-w-[80px]'">
                                        
                                        <!-- Hover Tooltip -->
                                        <div class="absolute bottom-full mb-2.5 left-1/2 -translate-x-1/2 min-w-[180px] sm:min-w-[200px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-30 pointer-events-none shadow-xl space-y-1.5 text-left">
                                            <div class="flex items-center justify-between gap-1.5 pb-1 border-b border-white/10 flex-wrap">
                                                <span class="font-bold text-xs text-sky-200 break-words" x-text="group.forecast.display_title"></span>
                                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black shrink-0 tracking-wider bg-[#003049] text-white">Forecast</span>
                                            </div>
                                            <div class="text-white font-black text-xs sm:text-sm" x-text="group.forecast.coaches + ' Coaches'"></div>
                                            <div class="text-gray-300 text-xs flex items-center justify-between">
                                                <span>Ratio:</span>
                                                <span class="font-bold text-white">~1 coach / 4 pax</span>
                                            </div>
                                        </div>

                                        <!-- Value Label Above Bar -->
                                        <span class="text-xs sm:text-sm font-black mb-1 truncate max-w-full text-[#003049]"
                                              x-text="group.forecast.coaches">
                                        </span>

                                        <!-- Bar (Solid Ocean Navy, Wider Desktop Profile) -->
                                        <div class="w-full rounded-t-md transition-all duration-200 group-hover:opacity-90 origin-bottom relative cursor-pointer bg-[#00C3D0]"
                                             :style="'height: ' + getBarHeight(group.forecast, 'coaches') + '%;'">
                                        </div>

                                    </div>
                                </template>

                            </div>

                        </div>
                    </template>
                </div>

                <!-- Labels (Short Month Only, No Year) -->
                <div class="flex items-center justify-around gap-2 sm:gap-4 px-2 sm:px-4 text-center pt-2">
                    <template x-for="(group, gIdx) in monthGroups" :key="'label_coaches_' + group.month_key">
                        <div class="flex-1 max-w-[110px] sm:max-w-[130px] lg:max-w-[150px] min-w-0 truncate" :title="group.full_month_name">
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block truncate" x-text="group.month_label"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

    </div>

</div>
