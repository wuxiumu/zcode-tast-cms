import { useEffect, useState } from 'react'
import { Card, ErrorBlock, SpinLoading } from 'antd-mobile'
import { useNavigate } from 'react-router-dom'
import { api } from '../api'

export default function Home() {
  const [tree, setTree] = useState<any[] | null>(null)
  const nav = useNavigate()

  useEffect(() => {
    api('/categories')
      .then(setTree)
      .catch(() => setTree([]))
  }, [])

  if (tree === null) {
    return (
      <div style={{ textAlign: 'center', paddingTop: 100 }}>
        <SpinLoading />
      </div>
    )
  }

  return (
    <div>
      <h3 style={{ padding: '4px 4px 12px', margin: 0 }}>选择分类，领取发布任务</h3>
      {tree.length === 0 && <ErrorBlock status="empty" title="暂无分类" description="请等待运营配置" />}
      {tree.map((g) => (
        <Card key={g.id} title={<span style={{ fontSize: 16 }}>{g.name}</span>} style={{ marginBottom: 12 }}>
          {g.children.length === 0 && <div className="h5-muted">暂无子分类</div>}
          {g.children.map((c: any) => (
            <div key={c.id} className="h5-row" onClick={() => nav(`/c/${c.id}`)}>
              <span style={{ fontSize: 15 }}>{c.name}</span>
              <span className="h5-muted">{c.material_count} 个任务 ›</span>
            </div>
          ))}
        </Card>
      ))}
    </div>
  )
}
