export const TASK_STATUS: Record<string, { text: string; color: string }> = {
  claimed: { text: '待发布', color: '#ff8f1f' },
  submitted: { text: '待审核', color: '#1677ff' },
  bonus_pending: { text: '数据审核中', color: '#1677ff' },
  bonus_open: { text: '可补数据', color: '#00b578' },
  finished: { text: '已完成', color: '#8f8f8f' },
  expired: { text: '已过期', color: '#8f8f8f' },
}

export const METRIC_TEXT: Record<string, string> = {
  views: '播放量',
  likes: '点赞量',
  comments: '评论量',
}

export function fmtMoney(cents: number | string): string {
  const n = typeof cents === 'string' ? parseFloat(cents) : cents
  return (isNaN(n as number) ? 0 : n).toFixed(2)
}
