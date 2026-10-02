import { TabBar } from 'antd-mobile'
import { AppOutline, PayCircleOutline, UnorderedListOutline } from 'antd-mobile-icons'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'

const tabs = [
  { key: '/', title: '任务大厅', icon: <AppOutline /> },
  { key: '/tasks', title: '我的任务', icon: <UnorderedListOutline /> },
  { key: '/rewards', title: '我的奖励', icon: <PayCircleOutline /> },
]

export default function Layout() {
  const nav = useNavigate()
  const loc = useLocation()
  const active = loc.pathname.startsWith('/tasks')
    ? '/tasks'
    : loc.pathname.startsWith('/rewards')
      ? '/rewards'
      : '/'
  return (
    <div style={{ maxWidth: 480, margin: '0 auto', minHeight: '100vh', paddingBottom: 56 }}>
      <div style={{ padding: 12 }}>
        <Outlet />
      </div>
      <div
        style={{
          position: 'fixed',
          bottom: 0,
          left: '50%',
          transform: 'translateX(-50%)',
          width: '100%',
          maxWidth: 480,
          borderTop: '1px solid #eee',
          background: '#fff',
          zIndex: 10,
        }}
      >
        <TabBar activeKey={active} onChange={(k) => nav(k)}>
          {tabs.map((t) => (
            <TabBar.Item key={t.key} icon={t.icon} title={t.title} />
          ))}
        </TabBar>
      </div>
    </div>
  )
}
