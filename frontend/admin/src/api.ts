const TOKEN_KEY = 'sc_admin_token'

/** 后端 API 域名（前后端分离）：.env VITE_API_BASE 配置，留空 = 同源 */
export const API_BASE = (import.meta.env.VITE_API_BASE ?? String('')).replace(/\/$/, '')

/** 管理端部署子路径（vite base，默认 /admin/） */
export const BASE_URL = (import.meta.env.BASE_URL ?? '/admin/').replace(/\/$/, '')

export function getToken(): string {
  return localStorage.getItem(TOKEN_KEY) || ''
}
export function setToken(t: string): void {
  localStorage.setItem(TOKEN_KEY, t)
}
export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY)
}

export class ApiError extends Error {
  code: number
  data: unknown
  constructor(code: number, msg: string, data?: unknown) {
    super(msg)
    this.code = code
    this.data = data
  }
}

export async function api(path: string, opts: { method?: string; body?: unknown; form?: FormData } = {}): Promise<any> {
  const headers: Record<string, string> = {}
  const t = getToken()
  if (t) headers.Authorization = `Bearer ${t}`
  let body: string | FormData | undefined
  if (opts.form) {
    body = opts.form
  } else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(opts.body)
  }
  let res: Response
  try {
    res = await fetch(`${API_BASE}/api/v1${path}`, { method: opts.method ?? 'GET', headers, body })
  } catch (e: any) {
    // 网络层失败：断网 / 跨域被浏览器拦截 / 域名不可达
    throw new ApiError(-1, `网络请求失败：${e?.message ?? e}（跨域被拦截请检查 API 域名的 CORS 配置）`)
  }
  const text = await res.text()
  let data: any
  try {
    data = JSON.parse(text)
  } catch {
    // 网关层错误通常是 HTML：413 上传超限 / 502 PHP 未运行 / 404 路由未配置
    throw new ApiError(res.status, `接口返回异常（HTTP ${res.status}，非 JSON）：多为 413 上传超限、502 后端未运行或路由未配置`)
  }
  if (data.code === 1002) {
    clearToken()
    if (!location.pathname.startsWith(`${BASE_URL}/login`)) location.href = `${BASE_URL}/login`
  }
  if (data.code !== 0) throw new ApiError(data.code, data.msg ?? '请求失败', data.data)
  return data.data
}

export async function upload(file: File, scene: 'image' | 'video' = 'image'): Promise<string> {
  const fd = new FormData()
  fd.append('file', file)
  fd.append('scene', scene)
  const data = await api('/admin/uploads', { method: 'POST', form: fd })
  return data.url as string
}
