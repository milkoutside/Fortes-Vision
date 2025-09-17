import axios from 'axios'

const state = () => ({
    // key: `${projectId}:${batchId}:${imageId}:${date}` -> { statusId, completed, color }
    cellsByKey: {},
    // Временное выделение ячеек пользователем
    selectedKeys: new Set(),
})

const getters = {
    getCell: (state) => (projectId, batchId, imageId, date) => {
        const key = `${projectId}:${batchId}:${imageId}:${date}`
        return state.cellsByKey[key] || null
    },
    isSelected: (state) => (projectId, batchId, imageId, date) => {
        const key = `${projectId}:${batchId}:${imageId}:${date}`
        return state.selectedKeys.has(key)
    },
    hasSelectedCellsWithStatus: (state) => {
        for (const key of state.selectedKeys) {
            const cell = state.cellsByKey[key]
            if (cell && cell.statusId) return true
        }
        return false
    },
    isSelectedGroupCompleted: (state) => {
        if (state.selectedKeys.size === 0) return false
        for (const key of state.selectedKeys) {
            const cell = state.cellsByKey[key]
            if (!cell || !cell.completed) return false
        }
        return true
    }
}

const mutations = {
    // Внутренний помощник для нормализации даты к YYYY-MM-DD
    _normalizeDate(_, date) {
        if (!date) return date
        if (typeof date === 'string') return date.split('T')[0]
        try {
            const d = new Date(date)
            return isNaN(d.getTime()) ? date : d.toISOString().split('T')[0]
        } catch (e) {
            return date
        }
    },
    setCells(state, cells) {
        for (const cell of cells) {
            const dateKey = mutations._normalizeDate(null, cell.date)
            const key = `${cell.project_id}:${cell.batch_id}:${cell.image_id}:${dateKey}`
            state.cellsByKey[key] = {
                id: cell.id,
                statusId: cell.status_id,
                completed: !!cell.completed,
            }
        }
    },
    upsertCells(state, rows) {
        for (const row of rows) {
            const dateKey = mutations._normalizeDate(null, row.date)
            const key = `${row.project_id}:${row.batch_id}:${row.image_id}:${dateKey}`
            state.cellsByKey[key] = {
                ...(state.cellsByKey[key] || {}),
                statusId: row.status_id,
                completed: !!row.completed,
            }
        }
    },
    removeCells(state, rows) {
        for (const row of rows) {
            const dateKey = mutations._normalizeDate(null, row.date)
            const key = `${row.project_id}:${row.batch_id}:${row.image_id}:${dateKey}`
            delete state.cellsByKey[key]
            state.selectedKeys.delete(key)
        }
    },
    removeCellsById(state, ids) {
        if (!Array.isArray(ids) || ids.length === 0) return
        const idSet = new Set(ids.map(Number))
        for (const [key, cell] of Object.entries(state.cellsByKey)) {
            if (cell && idSet.has(Number(cell.id))) {
                delete state.cellsByKey[key]
                state.selectedKeys.delete(key)
            }
        }
    },
    applyMoves(state, moves) {
        // moves: Array<{ fromKey: string, toKey: string, cell: { statusId, completed }, wasSelected?: boolean }>
        for (const move of moves) {
            // remove from old position
            delete state.cellsByKey[move.fromKey]
            state.selectedKeys.delete(move.fromKey)
            // place to new position
            state.cellsByKey[move.toKey] = {
                statusId: move.cell.statusId,
                completed: !!move.cell.completed,
            }
            if (move.wasSelected) {
                state.selectedKeys.add(move.toKey)
            }
        }
    },
    selectKeys(state, keys) {
        for (const key of keys) state.selectedKeys.add(key)
    },
    clearSelection(state) {
        state.selectedKeys = new Set()
    },
    unselectKeys(state, keys) {
        for (const key of keys) state.selectedKeys.delete(key)
    },
}

const actions = {
    async fetchByImageAndPeriod({ commit }, { projectId, batchId, imageId, from, to }) {
        const { data } = await axios.get(`/api/projects/${projectId}/batches/${batchId}/images/${imageId}/colored-cells`, {
            params: { from, to }
        })
        commit('setCells', data.cells || [])
        return data.cells
    },
    async fetchByImageAndPeriodWithDelay({ commit }, { projectId, batchId, imageId, from, to }) {
        const { data } = await axios.get(`/api/projects/${projectId}/batches/${batchId}/images/${imageId}/delay`, {
            params: { from, to }
        })
        commit('setCells', data.cells || [])
        return data.cells
    },
    async bulkColor({ commit }, { items }) {
        const { data } = await axios.post('/api/colored-cells/bulk-color', { items })
        commit('setCells', data.cells || [])
        return data.cells
    },

    async bulkDelete({ commit, state, rootGetters }, { items }) {
        // Поддерживаем удаление по id и по композитному ключу
        const byId = []
        const byComposite = []
        for (const it of items || []) {
            if (it && typeof it.id !== 'undefined' && it.id !== null) byId.push(Number(it.id))
            else if (it && typeof it.project_id !== 'undefined') byComposite.push(it)
        }

        // Определяем удалённые Delay только среди композитных элементов
        const delayStatusGetter = rootGetters['statuses/delayStatus']
        const delayStatus = typeof delayStatusGetter === 'function' ? delayStatusGetter() : null
        const delaysByRow = new Map() // key: `${project}:${batch}:${image}` => Set<YYYY-MM-DD>

        if (delayStatus) {
            for (const row of byComposite) {
                const dateKey = mutations._normalizeDate(null, row.date)
                const key = `${row.project_id}:${row.batch_id}:${row.image_id}:${dateKey}`
                const cell = state.cellsByKey[key]
                if (cell && cell.statusId === delayStatus.id) {
                    const rowKey = `${row.project_id}:${row.batch_id}:${row.image_id}`
                    if (!delaysByRow.has(rowKey)) delaysByRow.set(rowKey, new Set())
                    delaysByRow.get(rowKey).add(dateKey)
                }
            }
        }

        // Удаляем на сервере и локально
        await axios.post('/api/colored-cells/bulk-delete', { items })
        if (byId.length) commit('removeCellsById', byId)
        if (byComposite.length) commit('removeCells', byComposite)

        // Если Delay нет среди удалённых — завершить
        if (!delayStatus || delaysByRow.size === 0) return true

        // Подготовим перемещения: все задачи правее удалённых Delay смещаем влево на их количество
        const moves = []

        const shiftIsoDate = (isoDate, deltaDays) => {
            try {
                const [y, m, d] = isoDate.split('-').map(Number)
                const dt = new Date(Date.UTC(y, m - 1, d))
                dt.setUTCDate(dt.getUTCDate() + deltaDays)
                const y2 = dt.getUTCFullYear()
                const m2 = String(dt.getUTCMonth() + 1).padStart(2, '0')
                const d2 = String(dt.getUTCDate()).padStart(2, '0')
                return `${y2}-${m2}-${d2}`
            } catch (e) {
                return isoDate
            }
        }

        const selectedLookup = state.selectedKeys

        for (const [rowKey, delaysSet] of delaysByRow.entries()) {
            const delays = Array.from(delaysSet).sort() // YYYY-MM-DD asc
            if (delays.length === 0) continue

            const prefix = `${rowKey}:`
            const rowEntries = Object.entries(state.cellsByKey).filter(([key]) => key.startsWith(prefix))

            for (const [key, cell] of rowEntries) {
                const date = key.slice(prefix.length)
                // пропускаем Delay
                if (cell && delayStatus && cell.statusId === delayStatus.id) continue

                let shift = 0
                for (const dd of delays) {
                    if (dd < date) shift++
                }
                if (shift === 0) continue

                const targetDate = shiftIsoDate(date, -shift)
                const toKey = `${rowKey}:${targetDate}`
                if (state.cellsByKey[toKey]) continue // не затираем существующее

                moves.push({
                    fromKey: key,
                    toKey,
                    cell: { statusId: cell.statusId, completed: !!cell.completed },
                    wasSelected: selectedLookup.has(key),
                })
            }
        }

        if (moves.length) {
            commit('applyMoves', moves)
        }

        return true
    },

    // Локальное удаление только визуальных Delay с последующим сдвигом задач влево
    async deleteLocalDelays({ commit, state, rootGetters }, { items }) {
        const delayStatusGetter = rootGetters['statuses/delayStatus']
        const delayStatus = typeof delayStatusGetter === 'function' ? delayStatusGetter() : null
        if (!delayStatus) return true

        const delaysByRow = new Map()
        for (const row of items || []) {
            const dateKey = mutations._normalizeDate(null, row.date)
            const key = `${row.project_id}:${row.batch_id}:${row.image_id}:${dateKey}`
            const cell = state.cellsByKey[key]
            if (cell && cell.statusId === delayStatus.id) {
                const rowKey = `${row.project_id}:${row.batch_id}:${row.image_id}`
                if (!delaysByRow.has(rowKey)) delaysByRow.set(rowKey, new Set())
                delaysByRow.get(rowKey).add(dateKey)
            }
        }

        // Удаляем локально
        commit('removeCells', items || [])

        if (delaysByRow.size === 0) return true

        const moves = []
        const shiftIsoDate = (isoDate, deltaDays) => {
            try {
                const [y, m, d] = isoDate.split('-').map(Number)
                const dt = new Date(Date.UTC(y, m - 1, d))
                dt.setUTCDate(dt.getUTCDate() + deltaDays)
                const y2 = dt.getUTCFullYear()
                const m2 = String(dt.getUTCMonth() + 1).padStart(2, '0')
                const d2 = String(dt.getUTCDate()).padStart(2, '0')
                return `${y2}-${m2}-${d2}`
            } catch (e) {
                return isoDate
            }
        }

        const selectedLookup = state.selectedKeys

        for (const [rowKey, delaysSet] of delaysByRow.entries()) {
            const delays = Array.from(delaysSet).sort() // YYYY-MM-DD asc
            if (delays.length === 0) continue

            const prefix = `${rowKey}:`
            const rowEntries = Object.entries(state.cellsByKey).filter(([key]) => key.startsWith(prefix))

            for (const [key, cell] of rowEntries) {
                const date = key.slice(prefix.length)
                // пропускаем Delay
                if (cell && delayStatus && cell.statusId === delayStatus.id) continue

                let shift = 0
                for (const dd of delays) {
                    if (dd < date) shift++
                }
                if (shift === 0) continue

                const targetDate = shiftIsoDate(date, -shift)
                const toKey = `${rowKey}:${targetDate}`
                if (state.cellsByKey[toKey]) continue // не затираем существующее

                moves.push({
                    fromKey: key,
                    toKey,
                    cell: { statusId: cell.statusId, completed: !!cell.completed },
                    wasSelected: selectedLookup.has(key),
                })
            }
        }

        if (moves.length) {
            commit('applyMoves', moves)
        }

        return true
    },

    async loadBatchColoredCellsByDateRange({ dispatch, rootGetters, state, commit }, { projectId, batchId, startDate, endDate }) {
        const images = rootGetters['projects/getBatchImages'](projectId, batchId) || []
        if (!images.length) return []

        const from = startDate
        const to = endDate

        const allResults = []
        const toRemove = []

        for (const image of images) {
            // 1) Загружаем с сервера актуальные клетки (с учётом дилея)
            const serverCells = await dispatch('fetchByImageAndPeriodWithDelay', {
                projectId,
                batchId,
                imageId: image.id,
                from,
                to,
            })
            allResults.push(...(serverCells || []))

            // 2) Построим множество допустимых ключей из ответа API
            const allowed = new Set(
                (serverCells || []).map(c => {
                    const d = mutations._normalizeDate(null, c.date)
                    return `${c.project_id}:${c.batch_id}:${c.image_id}:${d}`
                })
            )

            // 3) Найдём локальные ключи для строки (project:batch:image) и диапазона дат, которых нет в ответе
            const prefix = `${projectId}:${batchId}:${image.id}:`
            for (const key of Object.keys(state.cellsByKey)) {
                if (!key.startsWith(prefix)) continue
                const date = key.slice(prefix.length)
                if (date < from || date > to) continue
                if (!allowed.has(key)) {
                    toRemove.push({
                        project_id: projectId,
                        batch_id: batchId,
                        image_id: image.id,
                        date,
                    })
                }
            }
        }

        // 4) Удалим из стора лишние клетки, которых нет в ответе API
        if (toRemove.length) {
            commit('removeCells', toRemove)
        }

        return allResults
    },

    async clearBatchColoredCellsByDateRange({ commit, state }, { projectId, batchId, startDate, endDate }) {
        const from = startDate
        const to = endDate
        const items = []
        for (const key of Object.keys(state.cellsByKey)) {
            const [pId, bId, imageId, date] = key.split(':')
            if (String(pId) !== String(projectId) || String(bId) !== String(batchId)) continue
            if (date >= from && date <= to) {
                items.push({ project_id: Number(pId), batch_id: Number(bId), image_id: Number(imageId), date })
            }
        }
        if (items.length) {
            commit('removeCells', items)
        }
        return true
    },

    selectCellsByDescriptors({ commit }, descriptors) {
        const keys = descriptors.map(d => `${d.project_id}:${d.batch_id}:${d.image_id}:${d.date}`)
        commit('selectKeys', keys)
    },
    clearSelection({ commit }) {
        commit('clearSelection')
    },

    unselectCellsByDescriptors({ commit }, descriptors) {
        const keys = descriptors.map(d => `${d.project_id}:${d.batch_id}:${d.image_id}:${d.date}`)
        commit('unselectKeys', keys)
    },
}

export default {
    namespaced: true,
    state,
    getters,
    mutations,
    actions,
}

