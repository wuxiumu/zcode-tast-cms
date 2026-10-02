import { Button, Dialog, NavBar, SpinLoading, Toast } from 'antd-mobile'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { api } from '../api'
import { CopyText, MediaBody, NoticeBox } from '../components/Content'

export default function Material() {
  const { id } = useParams()
  const nav = useNavigate()
  const [m, setM] = useState<any>(null)

  const load = () => api(`/materials/${id}`).then(setM).catch((e) => Toast.show({ content: e.message }))
  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (!m) {
    return (
      <div style={{ textAlign: 'center', paddingTop: 100 }}>
        <SpinLoading />
      </div>
    )
  }

  const claim = async () => {
    const tag = m.tags?.[0]?.key
    if (!tag) {
      Toast.show({ content: '该素材暂无可用渠道' })
      return
    }
    try {
      await api('/tasks/claim', { method: 'POST', body: { material_ids: [m.id], tag } })
      await Dialog.alert({ content: '领取成功，去发布吧！', confirmText: '去我的任务' })
      nav('/tasks')
    } catch (e: any) {
      Dialog.alert({ content: e.message })
    }
  }

  return (
    <div>
      <NavBar onBack={() => nav(-1)}>{m.title}</NavBar>
      <div style={{ height: 12 }} />
      <MediaBody material={m} />
      <CopyText text={m.text ?? ''} />
      <NoticeBox notice={m.notice} />

      <div className="h5-card" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div>
          <div>
            <span className="h5-money" style={{ fontSize: 22 }}>
              ¥{m.reward_first}
            </span>
            <span className="h5-muted"> 首发奖励 · 数据好最高可得 ¥{m.reward_max}</span>
          </div>
          <div className="h5-muted" style={{ marginTop: 4 }}>
            {m.quota_left < 0 ? '不限量' : `剩余 ${m.quota_left} 份`} · 领取后 {m.window_hours} 小时内需提交发布凭证
          </div>
        </div>
      </div>

      {m.reward?.stats_rules?.length > 0 && (
        <div className="h5-card">
          <div style={{ fontWeight: 600, marginBottom: 8 }}>数据奖励（截图核定）</div>
          {m.reward.stats_rules.map((r: any, i: number) => (
            <div key={i} className="h5-row">
              <span>
                {r.metric === 'views' ? '播放' : r.metric === 'likes' ? '点赞' : '评论'} ≥ {r.gte}
              </span>
              <span className="h5-money">+¥{r.reward}</span>
            </div>
          ))}
          <div className="h5-muted" style={{ marginTop: 8 }}>
            叠加方式：{m.reward.stack_mode === 'all' ? '命中档位叠加' : '取命中最高档'} · 最多补发{' '}
            {m.reward.max_bonus_rounds} 轮
          </div>
        </div>
      )}

      <Button block color="primary" size="large" onClick={m.my_task ? () => nav(`/tasks/${m.my_task.task_id}`) : claim}>
        {m.my_task ? '已领取 · 查看我的任务' : '领取任务'}
      </Button>
      <div style={{ height: 20 }} />
    </div>
  )
}
