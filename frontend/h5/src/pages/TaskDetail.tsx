import { Button, Dialog, Input, NavBar, SpinLoading, Toast } from 'antd-mobile'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { api } from '../api'
import { CopyText, MediaBody, NoticeBox } from '../components/Content'
import { ShotsUploader } from '../components/ShotsUploader'
import { METRIC_TEXT, TASK_STATUS } from '../status'

export default function TaskDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const [data, setData] = useState<any>(null)

  // 表单状态
  const [url, setUrl] = useState('')
  const [shots, setShots] = useState<string[]>([])
  const [views, setViews] = useState('')
  const [likes, setLikes] = useState('')
  const [comments, setComments] = useState('')
  const [statsShots, setStatsShots] = useState<string[]>([])
  const [busy, setBusy] = useState(false)

  const load = () =>
    api(`/tasks/${id}`)
      .then((d) => {
        setData(d)
        setUrl(d.platform_url ?? '')
      })
      .catch((e) => Toast.show({ content: e.message }))
  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (!data) {
    return (
      <div style={{ textAlign: 'center', paddingTop: 100 }}>
        <SpinLoading />
      </div>
    )
  }

  const st = TASK_STATUS[data.status] ?? { text: data.status, color: '#999' }
  const rules = data.material?.reward?.stats_rules ?? []
  const nextRound = data.bonus_round + 1

  const withBusy = async (fn: () => Promise<void>) => {
    setBusy(true)
    Toast.show({ icon: 'loading', content: '提交中…', duration: 0 })
    try {
      await fn()
    } catch (e: any) {
      Toast.clear()
      Dialog.alert({ content: e.message })
    } finally {
      setBusy(false)
    }
  }

  const submitFirst = () =>
    withBusy(async () => {
      await api(`/tasks/${data.task_id}/submit`, {
        method: 'POST',
        body: { platform_url: url, screenshots: shots },
      })
      Toast.clear()
      Toast.show({ content: '已提交，等待审核' })
      setShots([])
      await load()
    })

  const submitStats = () =>
    withBusy(async () => {
      await api(`/tasks/${data.task_id}/stats-submit`, {
        method: 'POST',
        body: {
          round: nextRound,
          metrics: { views: Number(views) || 0, likes: Number(likes) || 0, comments: Number(comments) || 0 },
          screenshots: statsShots,
        },
      })
      Toast.clear()
      Toast.show({ content: '数据已提交，等待审核' })
      setStatsShots([])
      await load()
    })

  const reClaim = () =>
    withBusy(async () => {
      await api('/tasks/claim', { method: 'POST', body: { material_ids: [data.material_id], tag: data.tag_key } })
      Toast.clear()
      Toast.show({ content: '已重新领取' })
      await load()
    })

  return (
    <div>
      <NavBar onBack={() => nav('/tasks')}>任务详情</NavBar>
      <div style={{ height: 12 }} />

      <div className="h5-card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <span style={{ fontSize: 16, fontWeight: 600, color: st.color }}>{st.text}</span>
          <span className="h5-money" style={{ fontSize: 18 }}>
            已得 ¥{data.total_reward}
          </span>
        </div>
        <div className="h5-muted" style={{ marginTop: 6 }}>
          领取于 {data.claim_at}
          {data.status === 'claimed' ? ` · 请在 ${data.deadline_at} 前提交` : ''}
        </div>
        {data.status === 'claimed' && data.last_reject_reason && (
          <div style={{ marginTop: 8, background: '#fff1f0', color: '#cf1322', borderRadius: 6, padding: 8, fontSize: 13 }}>
            上次被驳回：{data.last_reject_reason}
          </div>
        )}
      </div>

      <div className="h5-card">
        <div style={{ fontWeight: 600, marginBottom: 10 }}>{data.material?.title}</div>
        <MediaBody material={data.material ?? {}} />
        <div style={{ height: 10 }} />
        <CopyText text={data.material?.text ?? ''} />
        <NoticeBox notice={data.notice} />
        {data.tag?.guide && (
          <div className="h5-card" style={{ background: '#f0f7ff' }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>发布引导 · {data.tag.name}</div>
            <div style={{ fontSize: 14, color: '#555' }}>{data.tag.guide}</div>
          </div>
        )}
      </div>

      {data.status === 'claimed' && (
        <div className="h5-card">
          <div style={{ fontWeight: 600, marginBottom: 10 }}>我已发布，提交凭证</div>
          <Input placeholder="粘贴发布链接（视频号可填 昵称+发布时间）" value={url} onChange={setUrl} clearable />
          <div style={{ height: 12 }} />
          <ShotsUploader value={shots} onChange={setShots} max={3} />
          <Button block color="primary" style={{ marginTop: 12 }} disabled={busy} onClick={submitFirst}>
            提交首发凭证（链接 + 截图）
          </Button>
        </div>
      )}

      {(data.status === 'submitted' || data.status === 'bonus_pending') && (
        <div className="h5-card" style={{ textAlign: 'center', padding: 24 }}>
          <div style={{ fontSize: 15 }}>凭证已提交，审核中…</div>
          <div className="h5-muted" style={{ marginTop: 6 }}>
            预计 24 小时内完成审核
          </div>
        </div>
      )}

      {data.status === 'bonus_open' && (
        <div className="h5-card">
          <div style={{ fontWeight: 600, marginBottom: 6 }}>
            数据达标了？补发第 {nextRound} 轮数据（已补 {data.bonus_round}/{data.bonus_max_rounds}）
          </div>
          <div className="h5-muted" style={{ marginBottom: 10 }}>
            奖励档位：{rules.map((r: any) => `${METRIC_TEXT[r.metric]}≥${r.gte} 奖¥${r.reward}`).join('；')}
          </div>
          <Input placeholder="播放量" type="number" value={views} onChange={setViews} />
          <div style={{ height: 8 }} />
          <Input placeholder="点赞量" type="number" value={likes} onChange={setLikes} />
          <div style={{ height: 8 }} />
          <Input placeholder="评论量" type="number" value={comments} onChange={setComments} />
          <div style={{ height: 12 }} />
          <ShotsUploader value={statsShots} onChange={setStatsShots} max={3} />
          <Button block color="primary" fill="outline" style={{ marginTop: 12 }} disabled={busy} onClick={submitStats}>
            提交数据截图（第 {nextRound} 轮）
          </Button>
        </div>
      )}

      {data.status === 'expired' && (
        <Button block color="warning" onClick={reClaim} disabled={busy}>
          任务已过期，重新领取
        </Button>
      )}

      <div className="h5-card">
        <div style={{ fontWeight: 600, marginBottom: 8 }}>奖励明细</div>
        {(data.rewards ?? []).length === 0 && <div className="h5-muted">暂无奖励</div>}
        {(data.rewards ?? []).map((r: any) => (
          <div key={r.rid} className="h5-row">
            <span>
              {r.type === 'first' ? '首发奖励' : `数据奖励 · 第${r.round}轮`}
              <span className="h5-muted" style={{ marginLeft: 6 }}>
                {r.created_at}
              </span>
            </span>
            <span className="h5-money">+¥{r.amount}</span>
          </div>
        ))}
      </div>
      <div style={{ height: 20 }} />
    </div>
  )
}
