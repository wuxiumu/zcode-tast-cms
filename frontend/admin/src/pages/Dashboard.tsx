import { Button, Card, Col, Row, Statistic } from 'antd'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api'

export default function Dashboard() {
  const [d, setD] = useState<any>(null)
  const nav = useNavigate()

  useEffect(() => {
    api('/admin/stats/overview').then(setD).catch(() => {})
  }, [])

  return (
    <div>
      <Row gutter={[16, 16]}>
        <Col span={6}>
          <Card>
            <Statistic title="累计领取任务" value={d?.claims_total ?? '-'} />
          </Card>
        </Col>
        <Col span={6}>
          <Card>
            <Statistic title="今日领取" value={d?.claims_today ?? '-'} />
          </Card>
        </Col>
        <Col span={6}>
          <Card>
            <Statistic title="累计提交凭证" value={d?.submissions_total ?? '-'} />
          </Card>
        </Col>
        <Col span={6}>
          <Card>
            <Statistic title="待审核" value={d?.pending_reviews ?? '-'} suffix={d?.pending_reviews > 0 ? '（需处理）' : ''} />
          </Card>
        </Col>
        <Col span={6}>
          <Card>
            <Statistic title="累计奖励支出（元）" value={d?.reward_total ?? '-'} />
          </Card>
        </Col>
        <Col span={6}>
          <Card>
            <Statistic title="注册用户" value={d?.users_total ?? '-'} />
          </Card>
        </Col>
        <Col span={6}>
          <Card>
            <Statistic title="在架素材" value={d?.materials_published ?? '-'} />
          </Card>
        </Col>
        <Col span={6}>
          <Card>
            <Statistic title="草稿/下架素材" value={d?.materials_other ?? '-'} />
          </Card>
        </Col>
      </Row>
      {d?.pending_reviews > 0 && (
        <Card style={{ marginTop: 16 }}>
          <Button type="primary" onClick={() => nav('/reviews')}>
            去审核中心处理 {d.pending_reviews} 条待审凭证 →
          </Button>
        </Card>
      )}
    </div>
  )
}
