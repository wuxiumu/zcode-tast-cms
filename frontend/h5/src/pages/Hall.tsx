import { Button, Checkbox, Dialog, ErrorBlock, NavBar, SpinLoading, Tabs, Toast } from 'antd-mobile'
import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { api } from '../api'

export default function Hall() {
  const { id } = useParams()
  const nav = useNavigate()
  const [tab, setTab] = useState('')
  const [tags, setTags] = useState<any[]>([])
  const [list, setList] = useState<any[]>([])
  const [catName, setCatName] = useState('')
  const [sel, setSel] = useState<string[]>([])
  const [loaded, setLoaded] = useState(false)

  const load = useCallback(
    async (t: string) => {
      setLoaded(false)
      try {
        const [tree, tagData] = await Promise.all([api('/categories'), api(`/categories/${id}/tags`)])
        for (const g of tree) {
          const hit = (g.children ?? []).find((c: any) => c.id === id)
          if (hit) setCatName(hit.name)
        }
        setTags(tagData)
        const data = await api(`/materials?category_id=${id}${t ? `&tag=${t}` : ''}&page_size=50`)
        setList(data.list ?? [])
      } catch (e: any) {
        Toast.show({ content: e.message })
      } finally {
        setLoaded(true)
      }
    },
    [id],
  )

  useEffect(() => {
    load(tab)
    setSel([])
  }, [tab, load])

  const toggle = (mid: string) => {
    setSel((s) => (s.includes(mid) ? s.filter((x) => x !== mid) : [...s, mid]))
  }

  const claim = async () => {
    const tag = tab || tags[0]?.key
    if (!tag) {
      Toast.show({ content: '该分类暂无可用渠道标签' })
      return
    }
    try {
      await api('/tasks/claim', { method: 'POST', body: { material_ids: sel, tag } })
      await Dialog.alert({ content: '领取成功，去发布吧！', confirmText: '去我的任务' })
      nav('/tasks')
    } catch (e: any) {
      const failed = e.data?.failed
      Dialog.alert({
        content: failed?.length ? `领取失败：${failed[0].reason}` : e.message,
      })
    }
  }

  return (
    <div>
      <NavBar back={null} style={{ background: 'transparent' }}>
        {catName || '任务大厅'}
      </NavBar>
      <Tabs activeKey={tab} onChange={setTab}>
        <Tabs.Tab title="全部" key="" />
        {tags.map((t) => (
          <Tabs.Tab title={`${t.name} ${t.material_count}`} key={t.key} />
        ))}
      </Tabs>
      <div style={{ height: 12 }} />
      {!loaded && (
        <div style={{ textAlign: 'center', paddingTop: 60 }}>
          <SpinLoading />
        </div>
      )}
      {loaded && list.length === 0 && <ErrorBlock status="empty" title="暂无任务" description="换个标签看看" />}
      {list.map((m) => (
        <div key={m.id} className="h5-card" style={{ display: 'flex', gap: 10 }}>
          <div style={{ paddingTop: 2 }} onClick={() => !m.claimed && toggle(m.id)}>
            <Checkbox checked={sel.includes(m.id)} disabled={m.claimed} />
          </div>
          <img
            src={m.cover}
            style={{ width: 72, height: 72, borderRadius: 8, objectFit: 'cover', background: '#eee' }}
            onClick={() => nav(`/m/${m.id}`)}
          />
          <div style={{ flex: 1, minWidth: 0 }} onClick={() => nav(`/m/${m.id}`)}>
            <div style={{ fontSize: 15, fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {m.title}
            </div>
            <div style={{ marginTop: 6 }}>
              <span className="h5-money" style={{ fontSize: 18 }}>
                ¥{m.reward_first}
              </span>
              <span className="h5-muted"> 首奖 · 最高 ¥{m.reward_max}</span>
            </div>
            <div style={{ marginTop: 6, display: 'flex', gap: 6 }}>
              {m.tags.map((t: any) => (
                <span key={t.key} style={{ fontSize: 12, background: '#f0f7ff', color: '#1677ff', borderRadius: 4, padding: '1px 6px' }}>
                  {t.name}
                </span>
              ))}
              <span className="h5-muted">{m.quota_left < 0 ? '不限量' : `剩 ${m.quota_left} 份`}</span>
              {m.claimed && (
                <span style={{ fontSize: 12, background: '#e8f7ef', color: '#00b578', borderRadius: 4, padding: '1px 6px' }}>
                  已领取
                </span>
              )}
            </div>
          </div>
        </div>
      ))}

      {sel.length > 0 && (
        <div
          style={{
            position: 'fixed',
            bottom: 60,
            left: '50%',
            transform: 'translateX(-50%)',
            width: '92%',
            maxWidth: 460,
            zIndex: 9,
          }}
        >
          <Button block color="primary" size="large" onClick={claim}>
            领取所选任务（{sel.length}）
          </Button>
        </div>
      )}
    </div>
  )
}
