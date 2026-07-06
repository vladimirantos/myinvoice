<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { notesApi, type Note } from '@/api/notes'
import { renderMarkdown } from '@/utils/markdown'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const notes = ref<Note[]>([])
const loading = ref(false)
const search = ref('')

const open = ref(false)
const saving = ref(false)
const preview = ref(false)
const draft = reactive({ id: 0, title: '', body: '' })

async function load() {
  loading.value = true
  try { notes.value = await notesApi.list() }
  finally { loading.value = false }
}
onMounted(load)

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return notes.value
  return notes.value.filter(n =>
    n.title.toLowerCase().includes(q) || n.body.toLowerCase().includes(q))
})

function newNote() {
  Object.assign(draft, { id: 0, title: '', body: '' })
  preview.value = false
  open.value = true
}
function editNote(n: Note) {
  Object.assign(draft, { id: n.id, title: n.title, body: n.body })
  preview.value = false
  open.value = true
}

async function save() {
  if (!draft.title.trim()) { toast.error(t('notes.title_required')); return }
  saving.value = true
  try {
    const payload = { title: draft.title.trim(), body: draft.body }
    if (draft.id) await notesApi.update(draft.id, payload)
    else await notesApi.create(payload)
    open.value = false
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message ?? t('common.error'))
  } finally { saving.value = false }
}

async function removeNote(n: Note) {
  if (!confirm(t('notes.confirm_delete', { title: n.title }))) return
  try {
    await notesApi.delete(n.id)
    toast.success(t('common.deleted'))
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.error?.message ?? t('common.error')) }
}

function fmtDate(s: string): string {
  try { return new Date(s.replace(' ', 'T')).toLocaleString() } catch { return s }
}
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('notes.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('notes.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite" @click="newNote"
        class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"/></svg>
        {{ t('notes.new') }}
      </button>
    </header>

    <input v-if="notes.length" v-model="search" type="search" :placeholder="t('notes.search_placeholder')"
      class="w-full h-10 px-3 mb-4 border border-neutral-300 rounded-md text-sm" />

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else-if="notes.length === 0" class="text-center text-neutral-500 py-12 text-sm">{{ t('notes.empty') }}</div>
    <div v-else-if="filtered.length === 0" class="text-center text-neutral-500 py-12 text-sm">{{ t('notes.no_match') }}</div>

    <div v-else class="space-y-4">
      <article v-for="n in filtered" :key="n.id" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
        <div class="flex items-baseline justify-between gap-3">
          <h2 class="text-base font-semibold text-neutral-900">{{ n.title }}</h2>
          <span class="text-xs text-neutral-400 shrink-0" :title="t('notes.created_at') + ': ' + fmtDate(n.created_at)">
            {{ fmtDate(n.updated_at) }}
          </span>
        </div>
        <div v-if="n.body.trim()" class="note-md prose prose-sm max-w-none mt-2" v-html="renderMarkdown(n.body)"></div>
        <div v-if="auth.canWrite" class="flex gap-4 mt-3 pt-3 border-t border-neutral-100 text-xs">
          <button @click="editNote(n)" class="cursor-pointer text-primary-600 hover:text-primary-700">{{ t('common.edit') }}</button>
          <button @click="removeNote(n)" class="cursor-pointer ml-auto text-danger-500 hover:text-danger-600">{{ t('common.delete') }}</button>
        </div>
      </article>
    </div>

    <!-- Modal -->
    <div v-if="open" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <form @submit.prevent="save" class="p-5 space-y-4">
          <h2 class="text-lg font-semibold">{{ draft.id ? t('notes.edit') : t('notes.new') }}</h2>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('notes.field_title') }} *</label>
            <input v-model="draft.title" type="text" maxlength="200" required :placeholder="t('notes.title_placeholder')"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-sm font-medium text-neutral-700">{{ t('notes.field_body') }}</label>
              <button type="button" @click="preview = !preview"
                class="cursor-pointer text-xs text-primary-600 hover:text-primary-700">
                {{ preview ? t('notes.back_to_edit') : t('notes.preview') }}
              </button>
            </div>
            <textarea v-if="!preview" v-model="draft.body" rows="12" :placeholder="t('notes.body_placeholder')"
              class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm font-mono leading-relaxed"></textarea>
            <div v-else class="note-md prose prose-sm max-w-none border border-neutral-200 rounded-md px-3 py-2 min-h-[16rem]"
              v-html="renderMarkdown(draft.body)"></div>
            <p class="text-xs text-neutral-400 mt-1">{{ t('notes.markdown_hint') }}</p>
          </div>
          <div class="flex justify-end gap-2 pt-2 border-t border-neutral-100">
            <button type="button" @click="open = false" class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :disabled="saving" class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md disabled:opacity-50 inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              {{ t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Minimální styling pro mini-markdown (zrcadlí .release-notes v admin/Update.vue). */
.note-md :deep(h1),
.note-md :deep(h2),
.note-md :deep(h3) {
  font-weight: 600;
  color: var(--color-neutral-900);
  margin: 1em 0 0.4em;
  line-height: 1.3;
}
.note-md :deep(h1) { font-size: 1.25rem; }
.note-md :deep(h2) { font-size: 1.1rem; }
.note-md :deep(h3) { font-size: 1rem; }
.note-md :deep(p) { margin: 0.4em 0; line-height: 1.55; color: var(--color-neutral-700); }
.note-md :deep(ul),
.note-md :deep(ol) {
  margin: 0.4em 0;
  padding-left: 1.5em;
  color: var(--color-neutral-700);
}
.note-md :deep(ul) { list-style: disc; }
.note-md :deep(ol) { list-style: decimal; }
.note-md :deep(li) { margin: 0.15em 0; }
.note-md :deep(code) {
  background: var(--color-neutral-100);
  color: var(--color-danger-600);
  padding: 0 4px;
  border-radius: 3px;
  font-size: 0.85em;
  font-family: "JetBrains Mono", Consolas, monospace;
}
.note-md :deep(pre) {
  background: #1e1e2e;
  color: #cdd6f4;
  padding: 0.75em 1em;
  border-radius: 6px;
  overflow-x: auto;
  margin: 0.6em 0;
  font-size: 0.85em;
  line-height: 1.5;
}
.note-md :deep(pre code) { background: transparent; color: inherit; padding: 0; }
.note-md :deep(strong) { font-weight: 600; color: var(--color-neutral-900); }
.note-md :deep(em) { font-style: italic; }
.note-md :deep(a) { color: var(--color-primary-600); text-decoration: underline; }
.note-md :deep(a:hover) { color: var(--color-primary-700); }
</style>
