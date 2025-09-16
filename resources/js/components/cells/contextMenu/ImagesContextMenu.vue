<template>
    <div>
        <div
            v-if="showContextMenuFlag"
            class="context-menu"
            :style="{ top: contextMenuPosition.y + 'px', left: contextMenuPosition.x + 'px' }"
            @click.stop
        >
            <div class="context-menu-header">Cells actions</div>
            <div class="context-menu-items">
                <template v-if="selectedStatus">
                    <div class="context-menu-item" @click="colorSelectedCells">
                        <i class="pi pi-palette"></i>
                        <span>Fill in "{{ selectedStatus.name }}"</span>
                    </div>
                </template>
                <div v-if="hasSelectedCellsWithStatus && !isSelectedGroupCompleted"
                     class="context-menu-item"
                     @click="completeSelectedCellsTask">
                    <i class="pi pi-check"></i>
                    <span>Complete the task</span>
                </div>
                <div class="context-menu-item" @click="clearSelection">
                    <i class="pi pi-times"></i>
                    <span>Remove selection</span>
                </div>
                <div class="context-menu-item" @click="clearSelectedCellsColor">
                    <i class="pi pi-trash"></i>
                    <span>Clear color</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount } from 'vue';
import { useStore } from 'vuex';

const store = useStore();

// Получаем состояние контекстного меню из хранилища
const showContextMenuFlag = computed(() => store.getters['ui/contextMenu/images/showContextMenu']);
const contextMenuPosition = computed(() => store.getters['ui/contextMenu/images/contextMenuPosition']);

// Получаем выбранный статус
const selectedStatus = computed(() => store.getters['statuses/selectedStatus']);

// Проверяем, есть ли у выделенных ячеек статус
const hasSelectedCellsWithStatus = computed(() =>
    store.getters['coloredCells/hasSelectedCellsWithStatus']
);

// Проверяем, завершена ли группа выделенных ячеек
const isSelectedGroupCompleted = computed(() =>
    store.getters['coloredCells/isSelectedGroupCompleted']
);

// Обработчик для закрытия меню при клике вне его
const handleOutsideClick = (event) => {
    if (showContextMenuFlag.value) {
        store.dispatch('ui/contextMenu/images/hideContextMenu');
    }
};

// Монтирование и размонтирование обработчика событий
onMounted(() => {
    document.addEventListener('click', handleOutsideClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleOutsideClick);
});

// Методы для действий в контекстном меню
const colorSelectedCells = async () => {
    const selectedStatusValue = selectedStatus.value
    if (!selectedStatusValue) return

    // Собираем выбранные ключи из стора coloredCells
    const items = []
    const selectedKeys = store.state.coloredCells.selectedKeys
    for (const key of selectedKeys) {
        const [projectId, batchId, imageId, date] = key.split(':')
        items.push({
            project_id: Number(projectId),
            batch_id: Number(batchId),
            image_id: Number(imageId),
            date,
            status_id: selectedStatusValue.id,
        })
    }
    if (items.length === 0) return
    store.commit('coloredCells/clearSelection');
    await store.dispatch('ui/contextMenu/images/hideContextMenu');
    await store.dispatch('coloredCells/bulkColor', { items });
};

const completeSelectedCellsTask = async () => {

};

const clearSelection = async () => {
    await store.dispatch('coloredCells/clearSelection')
};

const clearSelectedCellsColor = async () => {
    const selectedKeys = store.state.coloredCells.selectedKeys
    const items = []
    for (const key of selectedKeys) {
        const [projectId, batchId, imageId, date] = key.split(':')
        items.push({
            project_id: Number(projectId),
            batch_id: Number(batchId),
            image_id: Number(imageId),
            date,
        })
    }
    if (items.length === 0) return
    await store.dispatch('coloredCells/bulkDelete', { items })
};
</script>

<style scoped>
.context-menu {
    position: fixed;
    background-color: #ffffff;
    border-radius: 4px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    min-width: 180px;
    z-index: 1000;
    overflow: hidden;
}

.context-menu-header {
    padding: 8px 12px;
    background-color: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    font-weight: 500;
    font-size: 14px;
    color: #495057;
}

.context-menu-items {
    padding: 4px 0;
}

.context-menu-item {
    padding: 8px 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: background-color 0.2s;
    user-select: none;
}

.context-menu-item:hover {
    background-color: #f1f3f5;
}

.context-menu-item i {
    font-size: 16px;
    color: #6c757d;
    width: 16px;
    text-align: center;
}

.context-menu-item:hover i {
    color: #495057;
}

/* Дополнительные стили для PrimeVue */
:deep(.p-dialog-header) {
    border-bottom: 1px solid #e9ecef;
}

:deep(.p-dialog-footer) {
    border-top: 1px solid #e9ecef;
    padding-top: 1rem;
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.p-field {
    margin-bottom: 1.5rem;
}

.p-field label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.w-full {
    width: 100%;
}
</style>
