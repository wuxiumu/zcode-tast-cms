import { SpinLoading } from 'antd-mobile'
import { useEffect, useState } from 'react'
import { api } from '../api'

export default function Rewards() {
  const [list, setList] = useState<any[] | null>(null)

  useEffect(() => {
    api('/rewards')
      .then((d) => setList(d.list ?? []))
      .catch(() => setList([]))
  }, [])

  if (list === null) {
    return (
      <div style={{ textAlign: 'center', paddingTop: 100 }}>
        <SpinLoading />
      </div>
    )
  }

  const total = list.reduce((s, r) => s + parseFloat(r.amount || '0'), 0)

  return (
    <div>
      <div className="h5-card" style={{ textAlign: 'center', padding: 24 }}>
        <div className="h5-muted">累计奖励（元）</div>
        <div className="h5-money" style={{ fontSize: 34 }}>
          ¥{total.toFixed(2)}
        </div>
      </div>
      {list.length === 0 && (
        <div className="h5-muted" style={{ textAlign: 'center', paddingTop: 40 }}>
          暂无奖励记录
        </div>
      )}
      {list.map((r) => (
        <div key={r.rid} className="h5-card" style={{ display: 'flex', justifyContent: 'space-between' }}>
          <div>
            <div style={{ fontSize: 15 }}>
              {r.type === 'first' ? '首发奖励' : `数据奖励 · 第${r.round}轮`}
              <span
                style={{
                  marginLeft: 8,
                  fontSize: 12,
                  color: r.status === 'paid' ? '#00b578' : '#ff8f1f',
                }}
              >
                {r.status === 'paid' ? '已打款' : '已入账'}
              </span>
            </div>
            <div className="h5-muted" style={{ marginTop: 4 }}>
              {r.material_title} · {r.created_at}
            </div>
          </div>
          <div className="h5-money" style={{ fontSize: 17 }}>
            +¥{r.amount}
          </div>
        </div>
      ))}
    </div>
  )
}
