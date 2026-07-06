import { api } from './client'

export interface Note {
  id: number
  title: string
  body: string
  created_at: string
  updated_at: string
}

export interface NotePayload {
  title: string
  body: string
}

export const notesApi = {
  list: () => api.get<Note[]>('/notes').then(r => r.data),
  create: (data: NotePayload) => api.post<Note>('/notes', data).then(r => r.data),
  update: (id: number, data: NotePayload) => api.put<Note>(`/notes/${id}`, data).then(r => r.data),
  delete: (id: number) => api.delete<{ deleted: boolean }>(`/notes/${id}`).then(r => r.data),
}
