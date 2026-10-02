import { Button, SpinLoading, Tabs } from 'antd-mobile'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api'
import { TASK_STATUS } from '../status'

const groups = [
  { key: 'claimed', title: '待发布', match: ['claimed'] },
  { key: 'review', title: '审核中', match: ['submitted', 'bonus_pending'] },
  { key: 'bonus', title: '可补数据', match: ['bonus_open'] },
  { key: 'done', title: '已完成', match: ['finished'] },
  { key: 'expired', title: '已过期', match: ['expired'] },
]

export default function MyTasks() {
  const [tasks, setTasks] = useState<any[] | null>(null)
  const [tab, setTab] = useState('claimed')
  const nav = useNavigate()

  const load = () => api('/tasks').then((d) => setTasks(d.list ?? [])).catch(() => setTasks([]))
  useEffect(() => {
    load()
  }, [])

  if (tasks === null) {
    return (
      <div style={{ textAlign: 'center', paddingTop: 100 }}>
        <SpinLoading />
      </div>
    )
  }

  const group = groups.find((g) => g.key === tab)!
  const rows = tasks.filter((t) => group.match.includes(t.status))

  return (
    <div>
      <Tabs activeKey={tab} onChange={setTab}>
        {groups.map((g) => (
          <Tabs.Tab
            key={g.key}
            title={`${g.title} ${tasks.filter((t) => g.match.includes(t.status)).length}`}
          />
        ))}
      </Tabs>
      <div style={{ height: 12 }} />
      {rows.length === 0 && (
        <div className="h5-muted" style={{ textAlign: 'center', paddingTop: 60 }}>
          暂无任务
        </div>
      )}
      {rows.map((t) => {
        const st = TASK_STATUS[t.status] ?? { text: t.status, color: '#999' }
        return (
          <div key={t.task_id} className="h5-card" style={{ display: 'flex', gap: 10 }} onClick={() => nav(`/tasks/${t.task_id}`)}>
            <img src={t.material?.cover} style={{ width: 64, height: 64, borderRadius: 8, objectFit: 'cover', background: '#eee' }} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 15, fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {t.material?.title ?? t.material_id}
              </div>
              <div className="h5-muted" style={{ marginTop: 4 }}>
                {t.tag_key === 'xhs' ? '小红书' : t.tag_key === 'sph' ? '视频号' : t.tag_key} · 领取于 {t.claim_at}
              </div>
              <div style={{ marginTop: 6, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ fontSize: 12, color: st.color, background: `${st.color}14`, borderRadius: 4, padding: '1px 6px' }}>
                  {st.text}
                  {t.status === 'bonus_open' ? `（${t.bonus_round}/${t.bonus_max_rounds}）` : ''}
                </span>
                <span className="h5-money">¥{t.total_reward}</span>
              </div>
            </div>
          </div>
        )
      })}
      {rows.length > 0 && (
        <Button block fill="none" onClick={load} style={{ color: '#999' }}>
          刷新
        </Button>
      )}
    </div>
  )
}
