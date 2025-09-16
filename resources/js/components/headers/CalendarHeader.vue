<template>
    <div class="calendar-container">
        <!-- Горизонтальный календарь -->
        <div class="calendar-header" id="calendar-dates-scroll">
            <div class="dates-container">
                <div
                    v-for="(month, monthIndex) in threeMonthsData"
                    :key="monthIndex"
                    class="month-section"
                >
                    <div class="month-header">
                        <button
                            v-if="monthIndex === 0"
                            @click="goToPreviousThreeMonths"
                            class="nav-btn nav-btn-left"
                        >‹‹</button>
                        <div v-else class="nav-placeholder"></div>

                        <span class="month-title">{{ month.monthName }} {{ month.year }}</span>

                        <button
                            v-if="monthIndex === 2"
                            @click="goToNextThreeMonths"
                            class="nav-btn nav-btn-right"
                        >››</button>
                        <div v-else class="nav-placeholder"></div>
                    </div>
                    <div class="dates-row">
                        <div
                            v-for="(date, dateIndex) in month.dates"
                            :key="dateIndex"
                            class="date-cell"
                            :class="{
                                weekend: isWeekend(date),
                                today: isToday(date),
                                'current-month': monthIndex === 1
                            }"
                            @click="selectDate(date)"
                        >
                            <div class="day">{{ new Date(date).getDate() }}</div>
                            <div class="weekday">{{ getWeekday(new Date(date)) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import { useStore } from 'vuex'

const store = useStore();

const threeMonthsData = computed(() => store.getters['calendar/threeMonthsData']);
const isWeekend = (dateStr) => {
    const date = new Date(dateStr);
    return date.getDay() === 0 || date.getDay() === 6;
}

const isToday = (dateStr) => {
    const date = new Date(dateStr);
    const today = new Date();
    return date.toDateString() === today.toDateString();
}

const getWeekday = (date) => {
    return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][date.getDay()];
}

const selectDate = (date) => {

}

const goToPreviousThreeMonths = async () => {
    await store.dispatch('calendar/goToPreviousThreeMonths');

}

const goToNextThreeMonths = async () => {
    await store.dispatch('calendar/goToNextThreeMonths');
}

const handleCalendarScroll = (event) => {
    const targetScrollLeft = event.target.scrollLeft;
    const calendarCells = document.querySelector('.images-virtual-container');
    calendarCells.scrollLeft = targetScrollLeft;
}

// Добавляем синхронизацию скролла при монтировании компонента
onMounted(() => {
    const calendarHeader = document.getElementById('calendar-dates-scroll');

    if (calendarHeader) {
        calendarHeader.addEventListener('scroll', handleCalendarScroll, { passive: true });
    }
});

// Удаляем обработчики при размонтировании
onUnmounted(() => {
    const calendarHeader = document.getElementById('calendar-dates-scroll');

    if (calendarHeader) {
        calendarHeader.removeEventListener('scroll', handleCalendarScroll);
    }
});
</script>

<style scoped>
.calendar-container {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: white;
    height: 130px;
    min-height: 130px;
    position: sticky;
    top: 0;
    z-index: 100;
    box-sizing: border-box;
    flex-shrink: 0;
}

.calendar-header {
    background: white;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
    scroll-behavior: smooth;
    border-bottom: 1px solid #eee;
    height: 100%;
    box-sizing: border-box;
}

.calendar-header::-webkit-scrollbar {
    height: 2px;
}

.calendar-header::-webkit-scrollbar-track {
    background: transparent;
}

.calendar-header::-webkit-scrollbar-thumb {
    background-color: #bbb;
    border-radius: 1px;
}

.calendar-header::-webkit-scrollbar-thumb:hover {
    background-color: #999;
}

.dates-container {
    display: flex;
    min-width: 100%;
    height: 100%;
}

.month-section {
    display: flex;
    flex-direction: column;
    min-width: fit-content;
    border-right: 2px solid #ddd;
    border-bottom: 1px solid #ddd;
    height: 100%;
}

.month-section:last-child {
    border-right: none;
}

.month-header {
    font-size: 12px;
    font-weight: 600;
    color: #666;
    text-align: center;
    padding: 8px 12px;
    background: #f8f9fa;
    border-bottom: 1px solid #eee;
    white-space: nowrap;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 70%;
    box-sizing: border-box;
}

.month-title {
    flex: 1;
    text-align: center;
    margin: 0 8px;
    font-weight: 900;
    font-size: 2em;
}

.nav-btn {
    width: 32px;
    height: 32px;
    border: none;
    background: transparent;
    cursor: pointer;
    border-radius: 50%;
    font-size: 16px;
    font-weight: bold;
    color: #666;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    flex-shrink: 0;
}

.nav-btn:hover {
    background: #e9ecef;
    color: #333;
}

.nav-placeholder {
    width: 32px;
    height: 32px;
    flex-shrink: 0;
}

.dates-row {
    display: flex;
    flex: 1;
    min-width: max-content;
    height: 30%;
}

.date-cell {
    width: 3vw;
    min-width: 3vw;
    height: 100%;
    border-right: 1px solid #eee;
    text-align: center;
    padding: 2px;
    cursor: pointer;
    transition: background-color 0.2s;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    flex-shrink: 0;
    box-sizing: border-box;
}

.date-cell:hover {
    background: #f0f0f0;
}

.date-cell.weekend {
    background: #fafafa;
    color: #e74c3c;
    border-right: 1px solid #eee;
}

.date-cell.today {
    background: #3498db;
    color: white;
}

.date-cell.today:hover {
    background: #2980b9;
}

.date-cell.current-month {
    background: #f8f9fa;
}

.date-cell.current-month.weekend {
    background: #f0f0f0;
    border-right: 1px solid #eee;
}

.date-cell.current-month.today {
    background: #3498db;
}

.day {
    font-size: 12px;
    font-weight: 500;
    line-height: 1;
}

.weekday {
    font-size: 9px;
    color: #666;
    line-height: 1;
    margin-top: 1px;
}

.date-cell.today .weekday {
    color: rgba(255, 255, 255, 0.8);
}

.date-cell.weekend .weekday {
    color: #c0392b;
}

.date-cell.current-month .weekday {
    color: #555;
}
</style>
