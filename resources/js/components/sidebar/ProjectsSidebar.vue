<template>
    <div class="projects-sidebar">
        <div class="menu-header">
            <!-- Поиск -->
            <div class="search-container">
                <div class="p-input-icon-left">
                    <i class="pi pi-search"></i>
                    <input
                        type="text"
                        :value="searchQuery"
                        @input="handleSearchInput"
                        placeholder="Project search..."
                        class="search-input"
                    />
                </div>
            </div>
            <ProjectsFiltersDropDown @filtersChanged="handleFiltersChanged" />
        </div>

        <DynamicScroller
            ref="scroller"
            :items="virtualItems"
            :min-item-size="BASE_ITEM_HEIGHT"
            class="menu-content"
            key-field="id"
            @scroll="handleScroll"
            @resize="handleResize"
        >
            <template #default="{ item, index, active }">
                <!-- Нет результатов -->
                <DynamicScrollerItem
                    v-if="item.type === 'no-results'"
                    :item="item"
                    :active="active"
                    :data-index="index"
                    :size-dependencies="[]"
                >
                    <div class="no-results">
                        <i class="pi pi-info-circle"></i>
                        <span>Проекты не найдены</span>
                    </div>
                </DynamicScrollerItem>

                <!-- Проект -->
                <DynamicScrollerItem
                    v-else-if="item.type === 'project'"
                    :item="item"
                    :active="active"
                    :data-index="index"
                    :size-dependencies="[item.data.isExpanded, item.data.batchesCount]"
                >
                    <div class="project-group">
                        <!-- Основной пункт проекта -->
                        <div
                            class="menu-item project-item"
                            :class="{ expanded: item.data.isExpanded }"
                            @click="toggleProject(item.data.id)"
                            @contextmenu.prevent="showProjectContextMenu($event, item.data.id)"
                        >
                            <div class="item-info">
                                <span class="item-name" :class="{ 'project-closed': !item.data.isActive, 'project-open': item.data.isActive }">
                                    {{ item.data.name }}
                                </span>
                                <span class="item-count">{{ item.data.clientName }} | {{ formatDate(item.data.startDate) }} - {{ formatDate(item.data.endDate) }}</span>
                            </div>
                            <i
                                :class="['pi', item.data.isExpanded ? 'pi-chevron-down' : 'pi-chevron-right']"
                                class="expand-icon"
                            ></i>
                        </div>

                        <!-- Батчи проекта -->
                        <div v-if="item.data.isExpanded" class="subitems batches-list">
                            <div
                                v-for="batch in getProjectBatches(item.data.id)"
                                :key="batch.id"
                                class="batch-wrapper"
                            >
                                <!-- Батч -->
                                <div
                                    class="menu-item batch-item"
                                    :class="{ expanded: batch.isExpanded }"
                                    @click="toggleBatch(item.data.id, batch.id)"
                                    @contextmenu.prevent="showBatchContextMenu($event, item.data.id, batch.id)"
                                >
                                    <div class="item-info">
                                        <span class="item-name">{{ batch.name }}</span>
                                        <span class="item-count">{{ batch.images.length || 0 }} images</span>
                                    </div>
                                    <i
                                        :class="['pi', batch.isExpanded ? 'pi-chevron-down' : 'pi-chevron-right']"
                                        class="expand-icon"
                                    ></i>
                                </div>

                                <!-- Изображения батча -->
                                <div v-if="batch.isExpanded" class="subitems images-list">
                                    <div
                                        v-for="image in getBatchImages(item.data.id, batch.id)"
                                        :key="image.id"
                                        class="menu-item image-item"
                                        @contextmenu.prevent="showImageContextMenu($event, item.data.id, batch.id, image.id)"
                                    >
                                        <div class="item-info">
                                            <span class="item-name">{{ image.name }}</span>
                                            <span class="item-count">ID: {{ image.id }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </DynamicScrollerItem>

                <!-- Индикатор загрузки -->
                <DynamicScrollerItem
                    v-else-if="item.type === 'loading'"
                    :item="item"
                    :active="active"
                    :data-index="index"
                    :size-dependencies="[]"
                >
                    <div class="loading-indicator">
                        <i class="pi pi-spin pi-spinner"></i>
                        <span>{{ item.text || 'Загрузка...' }}</span>
                    </div>
                </DynamicScrollerItem>
            </template>
        </DynamicScroller>

        <!-- Контекстные меню -->
        <ProjectContextMenu />
        <BatchContextMenu />
        <ImageContextMenu />
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useStore } from 'vuex'
import ProjectsFiltersDropDown from "@/components/sidebar/filters/ProjectsFiltersDropDown.vue";
import ProjectContextMenu from './contextMenu/ProjectContextMenu.vue'
import BatchContextMenu from './contextMenu/BatchContextMenu.vue'
import ImageContextMenu from './contextMenu/ImageContextMenu.vue'


const scroller = ref(null)

// Константы
const ITEMS_PER_PAGE = 100
const BASE_ITEM_HEIGHT = window.innerHeight * 0.06
const SCROLL_THRESHOLD = 100 // px до края для загрузки
const MEMORY_BUFFER_PAGES = 2 // сколько страниц держать с каждой стороны

const store = useStore()

// Состояния
const searchQuery = ref('')
// Используем состояния из store вместо локальных ref
// Получаем состояние развернутых проектов и батчей для реактивности
// Теперь используем getters из store вместо прямого доступа к state

// Новая структура для страниц
const loadedPages = ref([]) // [{ page: 1, projects: [...], hasMore: true/false }]
const loadingPages = ref(new Set()) // чтобы не было гонок
const isInitialLoading = ref(false)
const isLoadingUp = ref(false)
const isLoadingDown = ref(false)

// Методы
const getProjectBatches = (projectId) => {
    const project = store.getters['projects/getProjectById'](projectId)
    if (!project || !project.batches) return []

    return project.batches.map(batch => ({
        ...batch,
        isExpanded: store.getters['projects/isBatchExpanded'](projectId, batch.id)
    }))
}

const getBatchImages = (projectId, batchId) => {
    const project = store.getters['projects/getProjectById'](projectId)
    if (!project || !project.batches) return []

    const batch = project.batches.find(b => b.id === batchId)
    return batch?.images || []
}

const handleSearchInput = (event) => {
    searchQuery.value = event.target.value
    // Обновляем поисковый запрос в store
    store.dispatch('sidebar/filters/setSearchQuery', event.target.value)
    // Запускаем поиск проектов
    store.dispatch('sidebar/filters/searchProjects')
}

const handleFiltersChanged = (filters) => {
    console.log('Фильтры изменились:', filters)
    console.log('Отфильтрованные проекты:', store.getters['sidebar/filters/filteredProjects'])
    console.log('Активные фильтры:', store.getters['sidebar/filters/hasActiveFilters'])
    console.log('Отображаемые проекты:', displayedProjects.value)
    // Поиск уже запущен в компоненте фильтров
}

// Методы для контекстных меню
const showProjectContextMenu = (event, projectId) => {
    store.dispatch('ui/contextMenu/projects/showContextMenu', {
        x: event.clientX,
        y: event.clientY,
        projectId: projectId
    })
    store.dispatch('ui/contextMenu/images/hideContextMenu')
    store.dispatch('ui/contextMenu/batches/hideContextMenu')
}

const showBatchContextMenu = (event, projectId, batchId) => {
    store.dispatch('ui/contextMenu/batches/showContextMenu', {
        x: event.clientX,
        y: event.clientY,
        projectId: projectId,
        batchId: batchId
    });
    store.dispatch('ui/contextMenu/images/hideContextMenu')
    store.dispatch('ui/contextMenu/projects/hideContextMenu')
}

const showImageContextMenu = (event, projectId, batchId, imageId) => {
    store.dispatch('sidebar/sidebarImageContextMenu/showContextMenu', {
        x: event.clientX,
        y: event.clientY,
        projectId: projectId,
        batchId: batchId,
        imageId: imageId
    })
    store.dispatch('ui/contextMenu/projects/hideContextMenu')
    store.dispatch('ui/contextMenu/batches/hideContextMenu')
}

const toggleProject = async (projectId) => {
    if (store.getters['projects/isProjectExpanded'](projectId)) {
       await  store.dispatch('projects/toggleProjectExpansion', projectId)
        // TODO: Удалить батчи и изображения из стора
    } else {
        await store.dispatch('projects/toggleProjectExpansion', projectId)
        // Загружаем батчи проекта
        await loadProjectBatches(projectId)
    }
}

const toggleBatch = async (projectId, batchId) => {
    if (store.getters['projects/isBatchExpanded'](projectId, batchId)) {
        store.dispatch('projects/toggleBatchExpansion', { projectId, batchId })
        // Удаляем изображения из стора
        // TODO: Удалить изображения из стора
        // Удаляем закрашенные ячейки для батча
        await clearBatchColoredCells(projectId, batchId)
    } else {
        store.dispatch('projects/toggleBatchExpansion', { projectId, batchId })
        // Загружаем изображения батча
        await loadBatchImages(projectId, batchId)
        // Загружаем ячейки для батча по текущим трем месяцам
        await loadBatchColoredCells(projectId, batchId)
    }
}

// Загрузка батчей проекта
const loadProjectBatches = async (projectId) => {
    try {
        await store.dispatch('projects/fetchProjectBatches', projectId)
    } catch (error) {
        console.error('Ошибка загрузки батчей проекта:', error)
    }
}

// Загрузка изображений батча
const loadBatchImages = async (projectId, batchId) => {
    try {
        await store.dispatch('projects/fetchBatchImages', { projectId, batchId })
    } catch (error) {
        console.error('Ошибка загрузки изображений батча:', error)
    }
}



// Загрузка закрашенных ячеек для батча по текущим трем месяцам
const loadBatchColoredCells = async (projectId, batchId) => {
    try {
        // Получаем даты текущих трех месяцев из календарного модуля
        const allDates = store.getters['calendar/allDatesInThreeMonths']
        if (allDates && allDates.length > 0) {
            const startDate = allDates[0]
            const endDate = allDates[allDates.length - 1]

            await store.dispatch('coloredCells/loadBatchColoredCellsByDateRange', {
                projectId: projectId,
                batchId: batchId,
                startDate: startDate,
                endDate: endDate
            })
        }
    } catch (error) {
        console.error('Ошибка загрузки закрашенных ячеек для батча:', error)
    }
}

// Очистка закрашенных ячеек для батча
const clearBatchColoredCells = async (projectId, batchId) => {
    try {
        // Получаем даты текущих трех месяцев из календарного модуля
        const allDates = store.getters['calendar/allDatesInThreeMonths']
        if (allDates && allDates.length > 0) {
            const startDate = allDates[0]
            const endDate = allDates[allDates.length - 1]

            await store.dispatch('coloredCells/clearBatchColoredCellsByDateRange', {
                projectId: projectId,
                batchId: batchId,
                startDate: startDate,
                endDate: endDate
            })
        }
    } catch (error) {
        console.error('Ошибка очистки закрашенных ячеек для батча:', error)
    }
}

// Получить все проекты из загруженных страниц
const allLoadedProjects = computed(() => {
    return loadedPages.value.flatMap(page => page.projects.map(project => ({
        ...project,
        isExpanded: store.getters['projects/isProjectExpanded'](project.id),
        batchesCount: getProjectBatches(project.id).length
    })))
})

// Watcher для отслеживания изменений в календаре и обновления ячеек для открытых батчей
watch(() => store.getters['calendar/allDatesInThreeMonths'], async (newDates, oldDates) => {
    if (newDates && newDates.length > 0 && (!oldDates || newDates[0] !== oldDates[0] || newDates[newDates.length - 1] !== oldDates[oldDates.length - 1])) {
        const startDate = newDates[0]
        const endDate = newDates[newDates.length - 1]

        // Обновляем ячейки только для открытых батчей
        const allProjects = store.getters['projects/allProjects']
        for (const project of allProjects) {
            if (store.getters['projects/isProjectExpanded'](project.id)) {
                const batches = store.getters['projects/getProjectBatches'](project.id)
                for (const batch of batches) {
                    if (store.getters['projects/isBatchExpanded'](project.id, batch.id)) {
                        await store.dispatch('coloredCells/loadBatchColoredCellsByDateRange', {
                            projectId: project.id,
                            batchId: batch.id,
                            startDate: startDate,
                            endDate: endDate
                        })
                    }
                }
            }
        }
    }
}, { deep: true })

// Основной computed для отображаемых проектов
const displayedProjects = computed(() => {
    // Проверяем, есть ли активные фильтры
    const hasActiveFilters = store.getters['sidebar/filters/hasActiveFilters']
    const filteredProjects = store.getters['sidebar/filters/filteredProjects']

    // Если есть активные фильтры - используем отфильтрованные проекты (даже если пустой массив)
    if (hasActiveFilters) {
        return filteredProjects.map(project => ({
            ...project,
            isExpanded: store.getters['projects/isProjectExpanded'](project.id),
            batchesCount: getProjectBatches(project.id).length
        }))
    }

    // Если есть поиск - фильтруем по поисковому запросу
    if (searchQuery.value.trim()) {
        const allProjects = store.getters['projects/allProjects']
        const filtered = allProjects.filter(project =>
            project.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
            (project.clientName && project.clientName.toLowerCase().includes(searchQuery.value.toLowerCase()))
        )
        return filtered.map(project => ({
            ...project,
            isExpanded: store.getters['projects/isProjectExpanded'](project.id),
            batchesCount: getProjectBatches(project.id).length
        }))
    }

    // Иначе возвращаем все проекты с дополнительными свойствами
    const allProjects = store.getters['projects/allProjects']
    return allProjects.map(project => ({
        ...project,
        isExpanded: store.getters['projects/isProjectExpanded'](project.id),
        batchesCount: getProjectBatches(project.id).length
    }))
})

// Виртуальные элементы для скроллера
const virtualItems = computed(() => {
    const items = []
    const hasActiveFilters = store.getters['sidebar/filters/hasActiveFilters']
    const isFiltersLoading = store.getters['sidebar/filters/isLoading']

    // Показываем загрузку фильтров
    if (isFiltersLoading) {
        items.push({ id: `loading-filters`, type: 'loading', text: 'Поиск проектов...' })
        return items
    }

    // Показываем начальную загрузку только если нет поиска и фильтров
    if (isInitialLoading.value && !searchQuery.value.trim() && !hasActiveFilters) {
        items.push({ id: `loading-initial`, type: 'loading', text: 'Загрузка проектов...' })
        return items
    }

    // Нет результатов
    if (displayedProjects.value.length === 0) {
        items.push({ id: `no-results`, type: 'no-results' })
        return items
    }

    // Проекты
    displayedProjects.value.forEach(project => {
        items.push({ id: `project-${project.id}`, type: 'project', data: project })
    })

    // Загрузка вниз (только если нет активных фильтров)
    if (isLoadingDown.value && !searchQuery.value.trim() && !hasActiveFilters) {
        items.push({ id: `loading-down`, type: 'loading', text: 'Загрузка следующих проектов...' })
    }

    return items
})

// Проверка, можно ли загрузить вниз
const maxLoadedPage = computed(() => loadedPages.value.length ? Math.max(...loadedPages.value.map(p => p.page)) : 1)
const canLoadDown = computed(() => {
    const hasActiveFilters = store.getters['sidebar/filters/hasActiveFilters']
    if (searchQuery.value.trim() || hasActiveFilters) return false
    const last = loadedPages.value.find(p => p.page === maxLoadedPage.value)
    return last ? last.hasMore : true
})

// Загрузка страницы
const loadPage = async (page, direction) => {
    const hasActiveFilters = store.getters['sidebar/filters/hasActiveFilters']
    if (loadingPages.value.has(page) || searchQuery.value.trim() || hasActiveFilters) return

    loadingPages.value.add(page)
    if (direction === 'down') isLoadingDown.value = true

    try {
        const response = await store.dispatch('projects/fetchProjectsByPage', {
            page,
            perPage: ITEMS_PER_PAGE
        })

        const projects = (response?.projects || []).map(project => ({ ...project }))

        // Добавить в массив страниц
        loadedPages.value.push({ page, projects, hasMore: projects.length === ITEMS_PER_PAGE })
        projects.forEach(project => store.commit('projects/ADD_PROJECT', project))

    } catch (e) {
        console.error('Ошибка загрузки страницы проектов', page, e)
    } finally {
        loadingPages.value.delete(page)
        if (direction === 'down') isLoadingDown.value = false
    }
}

// Управление памятью: удалять страницы, которые далеко от видимой области
const manageMemory = () => {
    const hasActiveFilters = store.getters['sidebar/filters/hasActiveFilters']
    if (loadedPages.value.length <= MEMORY_BUFFER_PAGES * 2 + 1 || searchQuery.value.trim() || hasActiveFilters) return

    // Определяем текущую страницу по скроллу
    const scrollerEl = scroller.value?.$el
    if (!scrollerEl) return

    const scrollTop = scrollerEl.scrollTop
    const itemHeight = BASE_ITEM_HEIGHT
    const firstVisibleIndex = Math.floor(scrollTop / itemHeight)
    const currentPage = Math.floor(firstVisibleIndex / ITEMS_PER_PAGE) + 1

    const minKeep = Math.max(1, currentPage - MEMORY_BUFFER_PAGES)
    const maxKeep = currentPage + MEMORY_BUFFER_PAGES

    // Удаляем страницы вне диапазона
    loadedPages.value = loadedPages.value.filter(pageObj => {
        if (pageObj.page < minKeep || pageObj.page > maxKeep) {
            const projectIds = pageObj.projects.map(project => project.id)
            projectIds.forEach(projectId => store.commit('projects/DELETE_PROJECT', projectId))
            return false
        }
        return true
    })
}

// Обработка скролла
const handleScroll = (event) => {
    const targetScrollTop = event.target.scrollTop;
    const calendarCells = document.querySelector('.images-virtual-container');
    calendarCells.scrollTop = targetScrollTop;
    const hasActiveFilters = store.getters['sidebar/filters/hasActiveFilters']
    const scrollTop = event.target.scrollTop
    const scrollHeight = event.target.scrollHeight
    const clientHeight = event.target.clientHeight

    // Если есть активные фильтры - не обрабатываем бесконечную прокрутку
    if (hasActiveFilters) {
        return
    }

    // Загрузка вниз
    if ((scrollHeight - clientHeight - scrollTop) <= SCROLL_THRESHOLD && canLoadDown.value && !isLoadingDown.value) {
        const nextPage = maxLoadedPage.value + 1
        loadPage(nextPage, 'down')
    }

    // Управление памятью
    manageMemory()
}

const handleResize = () => {
    nextTick(() => {
        if (scroller.value) scroller.value.forceUpdate()
    })
}

// Форматирование даты
const formatDate = (dateString) => {
    if (!dateString) return 'N/A'
    const date = new Date(dateString)
    return date.toLocaleDateString('ru-RU')
}

// Сброс и инициализация
const resetAndInitialize = async () => {
    const hasActiveFilters = store.getters['sidebar/filters/hasActiveFilters']
    if (searchQuery.value.trim() || hasActiveFilters) {
        // В режиме поиска или фильтрации не инициализируем страницы
        return
    }

    isInitialLoading.value = true
    loadedPages.value = []
    loadingPages.value = new Set()
    isLoadingDown.value = false

    // Очищаем состояние проектов в store
    store.commit('projects/SET_PROJECTS', [])

    await nextTick()
    if (scroller.value) scroller.value.scrollToItem(0)

    await loadPage(1, 'down')
    isInitialLoading.value = false
}

// Наблюдатель за изменениями в поиске и фильтрах
watch([searchQuery, () => store.getters['sidebar/filters/hasActiveFilters']], async ([newQuery, newHasFilters], [oldQuery, oldHasFilters]) => {
    if (newQuery !== oldQuery || newHasFilters !== oldHasFilters) {
        // Сбрасываем к началу списка
        await nextTick()
        if (scroller.value) {
            scroller.value.scrollToItem(0)
        }

        // Если очистили поиск и фильтры - перезагружаем обычный режим
        if (!newQuery && oldQuery && !newHasFilters && oldHasFilters) {
            await resetAndInitialize()
        }
    }
})

// Наблюдатель за отфильтрованными проектами
watch(() => store.getters['sidebar/filters/filteredProjects'], (newProjects, oldProjects) => {
    console.log('Отфильтрованные проекты изменились:', {
        newCount: newProjects.length,
        oldCount: oldProjects?.length,
        newProjects: newProjects.map(p => p.name),
        oldProjects: oldProjects?.map(p => p.name)
    })
}, { deep: true })

// Наблюдатель за отображаемыми проектами
watch(displayedProjects, (newProjects, oldProjects) => {
    console.log('Отображаемые проекты изменились:', {
        newCount: newProjects.length,
        oldCount: oldProjects?.length,
        newProjects: newProjects.map(p => p.name),
        oldProjects: oldProjects?.map(p => p.name)
    })
}, { deep: true })

// Обработчик для закрытия контекстных меню при клике вне их
const handleOutsideClick = (event) => {
    // Проверяем, есть ли открытые контекстные меню
    const projectsMenuOpen = store.getters['ui/contextMenu/projects/showContextMenu']
    const batchesMenuOpen = store.getters['ui/contextMenu/batches/showContextMenu']
    const imagesMenuOpen = store.getters['ui/contextMenu/images/showContextMenu']

    if (projectsMenuOpen || batchesMenuOpen || imagesMenuOpen) {
        if (projectsMenuOpen) {
            store.dispatch('ui/contextMenu/projects/hideContextMenu')
        }
        if (batchesMenuOpen) {
            store.dispatch('ui/contextMenu/batches/hideContextMenu')
        }
        if (imagesMenuOpen) {
            store.dispatch('ui/contextMenu/images/hideContextMenu')
        }
    }
}
const handleCalendarScroll = (event) => {
    const targetScrollTop = event.target.scrollTop;
    const calendarCells = document.querySelector('.images-calendar-grid');
    calendarCells.scrollTop = targetScrollTop;
}
onMounted(() => {
    const calendarHeader = document.querySelector('.menu-content');

    if (calendarHeader) {
        calendarHeader.addEventListener('scroll', handleCalendarScroll, { passive: true });
    }
    resetAndInitialize()
    document.addEventListener('click', handleOutsideClick)
})

onBeforeUnmount(() => {
    document.removeEventListener('click', handleOutsideClick)
})
</script>

<style scoped>
.projects-sidebar {
    height: 100%;
    background-color: #f8f9fa;
    border-right: 1px solid #dee2e6;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.menu-header {
    padding: 16px;
    height: 130px;
    border-bottom: 1px solid #dee2e6;
    background-color: #fff;
    box-sizing: border-box;
    flex-shrink: 0;
}

.search-container {
    margin-bottom: 16px;
}

.p-input-icon-left {
    position: relative;
}

.p-input-icon-left i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    z-index: 1;
}

.search-input {
    width: 100%;
    height: 36px;
    padding: 8px 12px 8px 36px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s ease;
}

.search-input:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
}

.menu-content {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
}

.no-results {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 32px 16px;
    color: #6c757d;
    font-size: 14px;
    height: 8vh;
    min-height: 8vh;
}

.loading-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 16px;
    color: #6c757d;
    font-size: 14px;
    height: 8vh;
    min-height: 8vh;
}

.loading-indicator .pi {
    font-size: 14px;
}

.project-group {
    width: 100%;
}

.batch-wrapper {
    width: 100%;
}

.menu-item {
    height: 6vh;
    min-height: 6vh;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    transition: background-color 0.2s ease;
    box-sizing: border-box;
}

.menu-item:hover {
    background-color: #e9ecef;
}

.item-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.item-name {
    font-weight: 500;
    color: #495057;
    font-size: 14px;
    line-height: 1.2;
}

/* Стили для статуса проекта */
.project-closed {
    color: #dc3545 !important; /* Красный для закрытых проектов */
}

.project-open {
    color: #28a745 !important; /* Зеленый для открытых проектов */
}

.item-count {
    font-size: 11px;
    color: #6c757d;
}

.expand-icon {
    color: #6c757d;
    font-size: 12px;
    transition: transform 0.2s ease;
}

.project-item {
    padding: 0 16px;
    background-color: #fff;
    border-bottom: 1px solid #e9ecef;
    height: 6vh;
    min-height: 6vh;
}

.batch-item {
    padding: 0 16px 0 32px;
    background-color: #f8f9fa;
    border-top: 1px solid #e9ecef;
    height: 6vh;
    min-height: 6vh;
}

.batch-item .item-name {
    font-weight: 400;
    font-size: 13px;
    color: #495057;
}

.image-item {
    padding: 0 16px 0 48px;
    background-color: #f1f3f4;
    border-top: 1px solid #e9ecef;
    height: 6vh;
    min-height: 6vh;
}

.image-item .item-name {
    font-weight: 400;
    font-size: 12px;
    color: #6c757d;
}

.subitems {
    animation: slideDown 0.2s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
    }
    to {
        opacity: 1;
        max-height: 500px;
    }
}
</style>
